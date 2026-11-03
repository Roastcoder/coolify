<?php

use App\Actions\Proxy\GetTraefikCertificates;
use App\Livewire\Settings\MailServer as MailServerPage;
use App\Models\InstanceSettings;
use App\Models\MailAlias;
use App\Models\MailDomain;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\MailServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Records the shell commands instead of running them on the Coolify host. */
class FakeMailServer extends MailServer
{
    public array $commands = [];

    public bool $running = false;

    public array $mailboxList = [];

    protected function run(array $commands, bool $throwError = true): ?string
    {
        $this->commands = [...$this->commands, ...$commands];
        $command = implode("\n", $commands);

        return match (true) {
            str_contains($command, 'docker inspect') => $this->running ? 'true' : 'false',
            str_contains($command, 'creds list') => implode("\n", $this->mailboxList),
            default => '',
        };
    }
}

beforeEach(function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    InstanceSettings::forceCreate(['id' => 0, 'public_ipv4' => '203.0.113.7']);
    Once::flush();

    $this->admin = User::factory()->create();
    $rootTeam->members()->attach($this->admin->id, ['role' => 'admin']);
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    $this->mail = new FakeMailServer;
    app()->instance(MailServer::class, $this->mail);
});

it('writes every domain and alias into the Maddy config', function () {
    MailDomain::create(['name' => 'example.com']);
    MailDomain::create(['name' => 'example.org']);
    MailAlias::create(['address' => 'sales@example.org', 'destination' => 'info@example.com']);

    expect($this->mail->hostname())->toBe('mail.example.com')
        ->and($this->mail->config())->toContain('$(local_domains) = example.com example.org')
        ->and($this->mail->config())->toContain('optional_step file /data/aliases')
        ->and($this->mail->aliasesFile())->toBe("sales@example.org: info@example.com\n")
        ->and($this->mail->dnsRecords('example.com')[0])->toMatchArray(['type' => 'A', 'name' => 'mail.example.com', 'value' => '203.0.113.7']);
});

it('adds a domain and starts the hidden mail container', function () {
    Livewire::test(MailServerPage::class)
        ->set('newDomain', ' Example.COM ')
        ->call('addDomain')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect(MailDomain::pluck('name')->all())->toBe(['example.com']);
    $commands = implode("\n", $this->mail->commands);
    expect($commands)->toContain('docker run -d --name coolify-mail')
        ->and($commands)->toContain('-p 25:25')
        ->and($commands)->toContain(MailServer::IMAGE)
        ->and($commands)->toContain('dynamic/coolify-mail.yaml')
        ->and($commands)->toContain('docker run -d --name coolify-webmail')
        ->and($commands)->toContain(base64_encode($this->mail->proxyRoute('mail.example.com')));
});

it('rejects a malformed domain', function () {
    Livewire::test(MailServerPage::class)
        ->set('newDomain', 'not a domain')
        ->call('addDomain')
        ->assertHasErrors('newDomain');

    expect(MailDomain::count())->toBe(0);
});

it('creates a mailbox with a one-time password passed on stdin', function () {
    MailDomain::create(['name' => 'example.com']);
    $this->mail->running = true;

    $page = Livewire::test(MailServerPage::class)
        ->set('newMailbox', 'info')
        ->set('newMailboxDomain', 'example.com')
        ->call('createMailbox')
        ->assertHasNoErrors()
        ->assertSet('revealedFor', 'info@example.com');

    $password = $page->get('revealed');
    $create = collect($this->mail->commands)->first(fn ($c) => str_contains($c, 'creds create'));
    expect($password)->toHaveLength(20)
        ->and($create)->toContain(base64_encode($password))
        ->and($create)->not->toContain($password)
        ->and($create)->toContain("docker exec -i coolify-mail maddy creds create 'info@example.com'");

    $page->call('hideRevealed')->assertSet('revealed', null);
});

it('ignores a mailbox that is not on the server', function () {
    MailDomain::create(['name' => 'example.com']);
    $this->mail->running = true;
    $this->mail->mailboxList = ['info@example.com'];

    Livewire::test(MailServerPage::class)->call('deleteMailbox', 'root@example.com; reboot');

    expect(collect($this->mail->commands)->contains(fn ($c) => str_contains($c, 'remove')))->toBeFalse();
});

it('copies the matching certificate from Traefik', function () {
    MailDomain::create(['name' => 'example.com']);
    $acme = json_encode(['letsencrypt' => ['Certificates' => [
        ['domain' => ['main' => 'app.example.com'], 'certificate' => base64_encode('OTHER'), 'key' => base64_encode('OTHERKEY')],
        ['domain' => ['main' => 'mail.example.com'], 'certificate' => base64_encode('CERT'), 'key' => base64_encode('KEY')],
    ]]]);
    $this->mock(GetTraefikCertificates::class)->shouldReceive('contents')->andReturn($acme);

    expect($this->mail->syncCertificate())->toBeTrue();
    $commands = implode("\n", $this->mail->commands);
    expect($commands)->toContain(base64_encode('CERT'))->and($commands)->not->toContain(base64_encode('OTHER'));
});

it('keeps non-admins out', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(MailServerPage::class)->assertRedirect(route('dashboard'));
});

it('points Coolify email at a noreply mailbox without showing its password', function () {
    MailDomain::create(['name' => 'example.com']);
    $this->mail->running = true;
    $this->mail->mailboxList = ['noreply@example.com'];

    Livewire::test(MailServerPage::class)
        ->call('useForCoolify')
        ->assertSet('revealed', null)
        ->assertDispatched('success');

    $settings = InstanceSettings::find(0);
    expect($settings->smtp_enabled)->toBeTrue()
        ->and($settings->smtp_host)->toBe('mail.example.com')
        ->and($settings->smtp_port)->toBe(587)
        ->and($settings->smtp_username)->toBe('noreply@example.com')
        ->and($settings->smtp_password)->toHaveLength(32)
        ->and(collect($this->mail->commands)->contains(fn ($c) => str_contains($c, "creds password 'noreply@example.com'")))->toBeTrue();
});

it('renders Coolify emails with the StandaloneCoders branding', function () {
    $html = view('emails.test')->render();

    expect($html)->toContain('standalonecoders-apple-touch-icon.png')
        ->and($html)->toContain('StandaloneCoders')
        ->and($html)->toContain('your Email settings are correct')
        ->and($html)->not->toContain('coolify.io/docs/contact');
});
