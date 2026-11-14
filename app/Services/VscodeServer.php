<?php

namespace App\Services;

use App\Models\AiSettings;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fork-only: VS Code in the browser (code-server, built from microsoft/vscode) next to the app.
 *
 * It runs as root with the host's file system at /host (a full host shell is `chroot /host`), and is
 * served at <app domain>/vscode. It has no login of its own: Traefik asks the app on every request
 * (routes/web.php: vscode.auth) and only lets instance admins through. The Claude Code extension is
 * installed on start and uses the Claude API key from Settings > AI assistant.
 */
class VscodeServer
{
    public const CONTAINER = 'coolify-vscode';

    public const IMAGE = 'codercom/code-server:4.145.2';

    public const PATH = '/vscode';

    private const DATA = '/data/coolify/vscode';

    public function isRunning(): bool
    {
        return trim((string) instant_remote_process(['docker inspect -f "{{.State.Running}}" '.self::CONTAINER.' 2>/dev/null || true'], $this->host(), false)) === 'true';
    }

    public function start(): void
    {
        $host = $this->appHost();
        $apiKey = (string) AiSettings::current()->api_key;
        $startup = 'code-server --install-extension anthropic.claude-code >/dev/null 2>&1 || true; '
            .'exec code-server --auth none --disable-telemetry --bind-addr 0.0.0.0:8080 /host/data/coolify';

        instant_remote_process([
            'mkdir -p '.self::DATA,
            'printf %s '.escapeshellarg(base64_encode($this->proxyRoute($host))).' | base64 -d > '.escapeshellarg($this->proxyFile()),
            'docker rm -f '.self::CONTAINER.' >/dev/null 2>&1 || true',
            'docker run -d --name '.self::CONTAINER.' --restart always --network coolify --user 0:0 '
                .'-e HOME=/root -e ANTHROPIC_API_KEY='.escapeshellarg($apiKey).' '
                .'-v '.self::DATA.':/root -v /:/host --entrypoint sh '.self::IMAGE.' -c '.escapeshellarg($startup).' >/dev/null',
        ], $this->host(), true, false, 600);
    }

    /** The folder VS Code shows for "All files". */
    public const ALL_FILES = '/host/data/coolify';

    /**
     * Clones the app's repository the first time it is opened, with the same git commands a deploy uses
     * (GitHub App token included), and returns its folder inside VS Code. Later opens keep the user's changes.
     */
    public function openProject(Application $application): string
    {
        if ($application->deploymentType() === 'deploy_key') {
            throw new RuntimeException('This app is cloned with a deploy key, which VS Code cannot use yet. Open "All files" instead.');
        }
        $name = (Str::slug($application->name) ?: 'app').'-'.substr($application->uuid, 0, 6);
        $hostDir = self::DATA.'/projects/'.$name;
        $git = $application->generateGitImportCommands(deployment_uuid: 'vscode', exec_in_docker: false, custom_base_dir: $hostDir);
        // The clone URL can carry a short-lived GitHub token: keep the plain address as origin instead.
        $origin = preg_replace('~//[^@/]*@~', '//', (string) $git['fullRepoUrl']);

        instant_remote_process([
            'mkdir -p '.escapeshellarg(self::DATA.'/projects'),
            'if [ ! -d '.escapeshellarg($hostDir.'/.git').' ]; then rm -rf '.escapeshellarg($hostDir).' && '.$git['commands']
                .' && git -C '.escapeshellarg($hostDir).' remote set-url origin '.escapeshellarg($origin).'; fi',
        ], $this->host(), true, false, 600);

        return '/root/projects/'.$name;
    }

    public function stop(): void
    {
        instant_remote_process([
            'docker rm -f '.self::CONTAINER.' >/dev/null 2>&1 || true',
            'rm -f '.escapeshellarg($this->proxyFile()),
        ], $this->host());
    }

    /** Routes <app domain>/vscode to code-server, behind the app's admin check. */
    public function proxyRoute(string $host): string
    {
        $path = self::PATH;

        return <<<YAML
http:
  routers:
    sc-vscode:
      rule: Host(`{$host}`) && PathPrefix(`{$path}`)
      entryPoints:
        - https
      middlewares:
        - sc-vscode-auth
        - sc-vscode-strip
      service: sc-vscode
      tls:
        certResolver: letsencrypt
  middlewares:
    sc-vscode-auth:
      forwardAuth:
        address: http://coolify:8080/vscode-auth
    sc-vscode-strip:
      stripPrefix:
        prefixes:
          - {$path}
  services:
    sc-vscode:
      loadBalancer:
        servers:
          - url: http://coolify-vscode:8080

YAML;
    }

    private function appHost(): string
    {
        $host = parse_url((string) InstanceSettings::get()->fqdn, PHP_URL_HOST);
        if (! $host) {
            throw new RuntimeException('Set the instance domain in Settings first.');
        }

        return $host;
    }

    private function proxyFile(): string
    {
        return rtrim($this->host()->proxyPath(), '/').'/dynamic/sc-vscode.yaml';
    }

    protected function host(): Server
    {
        return Server::findOrFail(0);
    }
}
