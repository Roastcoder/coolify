<?php

namespace App\Services;

use App\Actions\Proxy\GetTraefikCertificates;
use App\Models\MailAlias;
use App\Models\MailDomain;
use App\Models\Server;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fork-only: the built-in mail server. Maddy runs in a container named coolify-mail on the
 * Coolify host; it never shows up as a resource. Domains and aliases live in Coolify's database
 * and are written to Maddy's config, mailboxes live in Maddy itself.
 * Maddy can only get certificates by DNS challenge, so Traefik gets one for the mail hostname
 * and syncCertificate() copies it into Maddy's data folder (Maddy rereads it every minute).
 * Roundcube webmail runs as coolify-webmail and Traefik serves it on the mail hostname.
 */
class MailServer
{
    public const CONTAINER = 'coolify-mail';

    public const IMAGE = 'foxcpp/maddy:0.9.6';

    public const DATA_PATH = '/data/coolify/mail';

    public const PORTS = [25, 143, 465, 587, 993];

    public const WEBMAIL_CONTAINER = 'coolify-webmail';

    public const WEBMAIL_IMAGE = 'roundcube/roundcubemail:1.6.20-apache';

    public function primaryDomain(): ?string
    {
        return MailDomain::query()->orderBy('id')->value('name');
    }

    public function hostname(): ?string
    {
        $primary = $this->primaryDomain();

        return $primary ? "mail.{$primary}" : null;
    }

    public function isRunning(): bool
    {
        return trim((string) $this->run(['docker inspect -f "{{.State.Running}}" '.self::CONTAINER.' 2>/dev/null || true'], false)) === 'true';
    }

    /** Writes the config, aliases and proxy route, then starts Maddy or makes it reload. */
    public function apply(): void
    {
        $hostname = $this->hostname();
        if (! $hostname) {
            throw new RuntimeException('Add a domain first.');
        }

        $data = self::DATA_PATH;
        $proxyFile = rtrim($this->host()->proxyPath(), '/').'/dynamic/coolify-mail.yaml';
        $ports = collect(self::PORTS)->map(fn (int $port) => "-p {$port}:{$port}")->implode(' ');

        $this->run([
            "mkdir -p {$data}/tls",
            $this->writeFile("{$data}/maddy.conf", $this->config()),
            $this->writeFile("{$data}/aliases", $this->aliasesFile()),
            $this->writeFile($proxyFile, $this->proxyRoute($hostname)),
            // Maddy refuses to start without a certificate; a short-lived one bridges the wait for Let's Encrypt.
            "[ -f {$data}/tls/fullchain.pem ] || openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj ".escapeshellarg("/CN={$hostname}")." -keyout {$data}/tls/privkey.pem -out {$data}/tls/fullchain.pem 2>/dev/null",
            'if [ "$(docker inspect -f "{{.State.Running}}" '.self::CONTAINER.' 2>/dev/null)" = "true" ]; then docker kill -s USR2 '.self::CONTAINER.' >/dev/null; '
                .'else docker rm -f '.self::CONTAINER.' >/dev/null 2>&1; docker run -d --name '.self::CONTAINER.' --restart unless-stopped '
                .'-e MADDY_HOSTNAME='.escapeshellarg($hostname).' -e MADDY_DOMAIN='.escapeshellarg((string) $this->primaryDomain())." {$ports} -v {$data}:/data ".self::IMAGE.' >/dev/null; fi',
            "mkdir -p {$data}/webmail/db {$data}/webmail/config",
            $this->writeFile("{$data}/webmail/config/branding.php", "<?php\n\$config['product_name'] = 'StandaloneCoders Mail';\n"),
            $this->webmailCommand($hostname),
        ]);

        $this->syncCertificate();
    }

    public function stop(): void
    {
        $this->run(['docker rm -f '.self::CONTAINER.' '.self::WEBMAIL_CONTAINER.' >/dev/null 2>&1 || true']);
    }

    /** Copies Traefik's Let's Encrypt certificate for the mail hostname. False until Traefik has one. */
    public function syncCertificate(): bool
    {
        $hostname = $this->hostname();
        $contents = $hostname ? app(GetTraefikCertificates::class)->contents($this->host()) : null;
        if (! $contents) {
            return false;
        }

        $match = collect(json_decode($contents, true) ?: [])
            ->flatMap(fn ($resolver) => is_array($resolver) ? (array) data_get($resolver, 'Certificates', []) : [])
            ->first(fn ($certificate) => data_get($certificate, 'domain.main') === $hostname
                || in_array($hostname, (array) data_get($certificate, 'domain.sans', []), true));

        $certificate = base64_decode((string) data_get($match, 'certificate'), true);
        $key = base64_decode((string) data_get($match, 'key'), true);
        if (! $certificate || ! $key) {
            return false;
        }

        $data = self::DATA_PATH;
        $this->run([
            "mkdir -p {$data}/tls",
            $this->writeFile("{$data}/tls/fullchain.pem", $certificate),
            $this->writeFile("{$data}/tls/privkey.pem", $key),
            "chmod 600 {$data}/tls/privkey.pem",
        ]);

        return true;
    }

    /** @return array<int, string> */
    public function mailboxes(): array
    {
        $output = (string) $this->run(['docker exec '.self::CONTAINER.' maddy creds list']);

        return array_values(array_filter(array_map('trim', explode("\n", $output))));
    }

    public function createMailbox(string $email, string $password): void
    {
        $this->run([
            $this->withPassword($password, 'creds create '.escapeshellarg($email)),
            'docker exec '.self::CONTAINER.' maddy imap-acct create '.escapeshellarg($email),
        ]);
    }

    public function setPassword(string $email, string $password): void
    {
        $this->run([$this->withPassword($password, 'creds password '.escapeshellarg($email))]);
    }

    public function deleteMailbox(string $email): void
    {
        $email = escapeshellarg($email);
        $this->run([
            'docker exec '.self::CONTAINER." maddy imap-acct remove --yes {$email}",
            'docker exec '.self::CONTAINER." maddy creds remove --yes {$email}",
        ]);
    }

    /** The DKIM TXT value Maddy generated for a domain, or null before the first start with it. */
    public function dkimValue(string $domain): ?string
    {
        $file = escapeshellarg(self::DATA_PATH."/dkim_keys/{$domain}_default.dns");
        $value = trim((string) $this->run(["cat {$file} 2>/dev/null || true"], false));

        return $value === '' ? null : $value;
    }

    /** @return array<int, array{type: string, purpose: string, name: string, host: string, value: string, ok: ?bool}> */
    public function dnsRecords(string $domain, bool $check = false): array
    {
        $hostname = (string) $this->hostname();
        $dkim = $this->dkimValue($domain);

        $records = [
            ['type' => 'MX', 'purpose' => 'MX', 'name' => $domain, 'value' => $hostname],
            ['type' => 'TXT', 'purpose' => 'SPF', 'name' => $domain, 'value' => 'v=spf1 mx ~all'],
            ['type' => 'TXT', 'purpose' => 'DMARC', 'name' => "_dmarc.{$domain}", 'value' => "v=DMARC1; p=quarantine; rua=mailto:postmaster@{$domain}"],
            ['type' => 'TXT', 'purpose' => 'DKIM', 'name' => "default._domainkey.{$domain}", 'value' => $dkim ?? 'Shown after the mail server starts with this domain'],
        ];
        if ($domain === $this->primaryDomain()) {
            // The Coolify host is host.docker.internal to Coolify, so use the instance's public IP.
            $ip = (string) data_get(instanceSettings(), 'public_ipv4');
            array_unshift($records, ['type' => 'A', 'purpose' => 'Mail server address', 'name' => $hostname, 'value' => $ip]);
            // Set at the hosting provider, not the DNS provider; Gmail checks it matches the A record.
            $records[] = ['type' => 'PTR', 'purpose' => 'Reverse DNS', 'name' => $ip, 'value' => $hostname];
        }

        return array_map(fn (array $record) => [
            ...$record,
            // DNS panels such as Hostinger's want the name without the domain.
            'host' => match (true) {
                $record['type'] === 'PTR' => 'Reverse DNS of '.$record['name'],
                $record['name'] === $domain => '@',
                default => Str::beforeLast($record['name'], ".{$domain}"),
            },
            'ok' => $check ? $this->resolves($record) : null,
        ], $records);
    }

    public function aliasesFile(): string
    {
        return MailAlias::query()->orderBy('address')->get()
            ->map(fn (MailAlias $alias) => "{$alias->address}: {$alias->destination}")
            ->push('')
            ->implode("\n");
    }

    /** Maddy's stock Docker config, with all Coolify domains and the alias file. */
    public function config(): string
    {
        $domains = MailDomain::query()->orderBy('id')->pluck('name')->implode(' ');

        return str_replace('__LOCAL_DOMAINS__', $domains, <<<'CONF'
$(hostname) = {env:MADDY_HOSTNAME}
$(primary_domain) = {env:MADDY_DOMAIN}
$(local_domains) = __LOCAL_DOMAINS__

tls file /data/tls/fullchain.pem /data/tls/privkey.pem

auth.pass_table local_authdb {
    table sql_table {
        driver sqlite3
        dsn credentials.db
        table_name passwords
    }
}

storage.imapsql local_mailboxes {
    driver sqlite3
    dsn imapsql.db
}

hostname $(hostname)

table.chain local_rewrites {
    optional_step regexp "(.+)\+(.+)@(.+)" "$1@$3"
    optional_step static {
        entry postmaster postmaster@$(primary_domain)
    }
    optional_step file /data/aliases
}

msgpipeline local_routing {
    destination postmaster $(local_domains) {
        modify {
            replace_rcpt &local_rewrites
        }
        deliver_to &local_mailboxes
    }
    default_destination {
        reject 550 5.1.1 "User doesn't exist"
    }
}

smtp tcp://0.0.0.0:25 {
    limits {
        all rate 20 1s
        all concurrency 10
    }
    dmarc yes
    check {
        require_mx_record
        dkim
        spf
    }
    source $(local_domains) {
        reject 501 5.1.8 "Use Submission for outgoing SMTP"
    }
    default_source {
        destination postmaster $(local_domains) {
            deliver_to &local_routing
        }
        default_destination {
            reject 550 5.1.1 "User doesn't exist"
        }
    }
}

submission tls://0.0.0.0:465 tcp://0.0.0.0:587 {
    limits {
        all rate 50 1s
    }
    auth &local_authdb
    source $(local_domains) {
        check {
            authorize_sender {
                prepare_email &local_rewrites
                user_to_email identity
            }
        }
        destination postmaster $(local_domains) {
            deliver_to &local_routing
        }
        default_destination {
            modify {
                dkim $(primary_domain) $(local_domains) default
            }
            deliver_to &remote_queue
        }
    }
    default_source {
        reject 501 5.1.8 "Non-local sender domain"
    }
}

target.remote outbound_delivery {
    limits {
        destination rate 20 1s
        destination concurrency 10
    }
    mx_auth {
        dane
        mtasts {
            cache fs
            fs_dir mtasts_cache/
        }
        local_policy {
            min_tls_level encrypted
            min_mx_level none
        }
    }
}

target.queue remote_queue {
    target &outbound_delivery
    autogenerated_msg_domain $(primary_domain)
    bounce {
        destination postmaster $(local_domains) {
            deliver_to &local_routing
        }
        default_destination {
            reject 550 5.0.0 "Refusing to send DSNs to non-local addresses"
        }
    }
}

imap tls://0.0.0.0:993 tcp://0.0.0.0:143 {
    auth &local_authdb
    storage &local_mailboxes
}

CONF);
    }

    /** Serves webmail on the mail hostname, which also makes Traefik get the certificate Maddy uses. */
    public function proxyRoute(string $hostname): string
    {
        $webmail = self::WEBMAIL_CONTAINER;

        return <<<YAML
http:
  routers:
    coolify-mail-certificate:
      rule: Host(`{$hostname}`)
      entryPoints:
        - https
      service: coolify-webmail
      tls:
        certResolver: letsencrypt
    coolify-mail-http:
      rule: Host(`{$hostname}`)
      entryPoints:
        - http
      middlewares:
        - coolify-mail-https
      service: coolify-webmail
  middlewares:
    coolify-mail-https:
      redirectScheme:
        scheme: https
  services:
    coolify-webmail:
      loadBalancer:
        servers:
          - url: http://{$webmail}:80

YAML;
    }

    /** Starts Roundcube, or recreates it when the mail hostname changed. */
    public function webmailCommand(string $hostname): string
    {
        $data = self::DATA_PATH;
        $name = self::WEBMAIL_CONTAINER;
        $label = 'coolify.mail.hostname';
        $server = escapeshellarg("ssl://{$hostname}");

        return "if [ \"\$(docker inspect -f '{{index .Config.Labels \"{$label}\"}}' {$name} 2>/dev/null)\" != ".escapeshellarg($hostname).' ]; then '
            ."docker rm -f {$name} >/dev/null 2>&1; docker run -d --name {$name} --restart unless-stopped --network coolify "
            .'--label '.escapeshellarg("{$label}={$hostname}")
            ." -e ROUNDCUBEMAIL_DEFAULT_HOST={$server} -e ROUNDCUBEMAIL_DEFAULT_PORT=993"
            ." -e ROUNDCUBEMAIL_SMTP_SERVER={$server} -e ROUNDCUBEMAIL_SMTP_PORT=465"
            .' -e ROUNDCUBEMAIL_DB_TYPE=sqlite -e ROUNDCUBEMAIL_SKIN=elastic'
            ." -v {$data}/webmail/db:/var/roundcube/db -v {$data}/webmail/config:/var/roundcube/config "
            .self::WEBMAIL_IMAGE.' >/dev/null; fi';
    }

    protected function host(): Server
    {
        return Server::findOrFail(0);
    }

    protected function run(array $commands, bool $throwError = true): ?string
    {
        return instant_remote_process($commands, $this->host(), $throwError);
    }

    private function writeFile(string $path, string $contents): string
    {
        return 'printf %s '.escapeshellarg(base64_encode($contents)).' | base64 -d > '.escapeshellarg($path);
    }

    /** Maddy reads the password from stdin, so it never appears as a command argument inside the container. */
    private function withPassword(string $password, string $command): string
    {
        return 'printf %s '.escapeshellarg(base64_encode($password)).' | base64 -d | docker exec -i '.self::CONTAINER." maddy {$command}";
    }

    private function resolves(array $record): bool
    {
        if ($record['type'] === 'PTR') {
            return rtrim(strtolower((string) @gethostbyaddr($record['name'])), '.') === strtolower($record['value']);
        }

        $type = $record['type'] === 'A' ? DNS_A : ($record['type'] === 'MX' ? DNS_MX : DNS_TXT);
        $answers = @dns_get_record($record['name'], $type) ?: [];

        // Any SPF or DMARC record counts, since the owner may have tuned it; DKIM must match exactly.
        $version = strtok($record['value'], '; ');

        return collect($answers)->contains(fn (array $answer) => match ($record['type']) {
            'A' => ($answer['ip'] ?? null) === $record['value'],
            'MX' => rtrim(strtolower($answer['target'] ?? ''), '.') === strtolower($record['value']),
            default => $record['purpose'] === 'DKIM'
                ? preg_replace('/\s+/', '', (string) ($answer['txt'] ?? '')) === preg_replace('/\s+/', '', $record['value'])
                : str_starts_with((string) ($answer['txt'] ?? ''), $version),
        });
    }
}
