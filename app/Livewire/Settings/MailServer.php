<?php

namespace App\Livewire\Settings;

use App\Models\MailAlias;
use App\Models\MailDomain;
use App\Services\MailServer as Server;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Fork-only: Settings > Mail server. Manages the built-in Maddy mail server (App\Services\MailServer).
 */
class MailServer extends Component
{
    public string $newDomain = '';

    public string $newMailbox = '';

    public string $newMailboxDomain = '';

    public string $aliasLocal = '';

    public string $aliasDomain = '';

    public string $aliasDestination = '';

    #[Locked]
    public bool $running = false;

    /** @var array<int, string> */
    #[Locked]
    public array $mailboxes = [];

    /** @var array<string, array<int, array{type: string, name: string, value: string, ok: ?bool}>> */
    #[Locked]
    public array $dns = [];

    /** A new or reset password, shown once. */
    #[Locked]
    public ?string $revealed = null;

    #[Locked]
    public ?string $revealedFor = null;

    public function mount()
    {
        if (isInstanceAdmin() === false) {
            return redirect()->route('dashboard');
        }

        $this->refreshState();
    }

    /** Every later request runs shell commands on the Coolify host, so check again each time. */
    public function hydrate(): void
    {
        abort_unless(isInstanceAdmin(), 403);
    }

    public function refreshState(bool $checkDns = false): void
    {
        try {
            $server = app(Server::class);
            $this->running = $server->isRunning();
            $this->mailboxes = $this->running ? $server->mailboxes() : [];
            $this->dns = MailDomain::query()->orderBy('id')->pluck('name')
                ->mapWithKeys(fn (string $domain) => [$domain => $server->dnsRecords($domain, $checkDns)])
                ->all();
            $this->newMailboxDomain = $this->newMailboxDomain ?: (string) $server->primaryDomain();
            $this->aliasDomain = $this->aliasDomain ?: (string) $server->primaryDomain();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function checkDns(): void
    {
        $this->refreshState(true);
    }

    public function addDomain(): void
    {
        $this->newDomain = strtolower(trim($this->newDomain));
        $this->validate(['newDomain' => ['required', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/', 'unique:mail_domains,name']],
            ['newDomain.regex' => 'Enter a domain like example.com.']);

        MailDomain::create(['name' => $this->newDomain]);
        $this->newDomain = '';
        $this->applyChanges('Domain added.');
    }

    public function removeDomain(int $id): void
    {
        $domain = MailDomain::findOrFail($id);
        $hasMailboxes = collect($this->mailboxes)->contains(fn (string $mailbox) => Str::endsWith($mailbox, '@'.$domain->name));
        if ($hasMailboxes) {
            $this->dispatch('error', 'Delete the mailboxes of this domain first.');

            return;
        }

        MailAlias::where('address', 'like', '%@'.$domain->name)->delete();
        $domain->delete();
        MailDomain::exists() ? $this->applyChanges('Domain removed.') : $this->stop();
    }

    public function start(): void
    {
        $this->applyChanges('Mail server started.');
    }

    public function stop(): void
    {
        try {
            app(Server::class)->stop();
            $this->dispatch('success', 'Mail server stopped. Mailboxes and mail are kept.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
        $this->refreshState();
    }

    public function createMailbox(): void
    {
        $this->newMailbox = strtolower(trim($this->newMailbox));
        $this->validate([
            'newMailbox' => ['required', 'regex:/^[a-z0-9._-]+$/'],
            'newMailboxDomain' => ['required', 'exists:mail_domains,name'],
        ], ['newMailbox.regex' => 'Use letters, numbers, dots, dashes or underscores.']);

        $email = "{$this->newMailbox}@{$this->newMailboxDomain}";
        if (in_array($email, $this->mailboxes, true) || MailAlias::where('address', $email)->exists()) {
            $this->addError('newMailbox', 'This address is already in use.');

            return;
        }

        try {
            $password = Str::password(20, symbols: false);
            app(Server::class)->createMailbox($email, $password);
            $this->reveal($email, $password);
            $this->newMailbox = '';
            $this->dispatch('success', 'Mailbox created.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
        $this->refreshState();
    }

    public function resetPassword(string $email): void
    {
        if (! in_array($email, $this->mailboxes, true)) {
            return;
        }

        try {
            $password = Str::password(20, symbols: false);
            app(Server::class)->setPassword($email, $password);
            $this->reveal($email, $password);
            $this->dispatch('success', 'Password changed.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function deleteMailbox(string $email): void
    {
        if (! in_array($email, $this->mailboxes, true)) {
            return;
        }

        try {
            app(Server::class)->deleteMailbox($email);
            MailAlias::where('destination', $email)->delete();
            $this->dispatch('success', 'Mailbox and its mail deleted.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
        $this->revealed = $this->revealedFor === $email ? null : $this->revealed;
        $this->applyChanges();
    }

    public function addAlias(): void
    {
        $this->aliasLocal = strtolower(trim($this->aliasLocal));
        $this->validate([
            'aliasLocal' => ['required', 'regex:/^[a-z0-9._-]+$/'],
            'aliasDomain' => ['required', 'exists:mail_domains,name'],
            'aliasDestination' => ['required', 'in:'.implode(',', $this->mailboxes)],
        ], ['aliasLocal.regex' => 'Use letters, numbers, dots, dashes or underscores.', 'aliasDestination.in' => 'Choose a mailbox.']);

        $address = "{$this->aliasLocal}@{$this->aliasDomain}";
        if (in_array($address, $this->mailboxes, true) || MailAlias::where('address', $address)->exists()) {
            $this->addError('aliasLocal', 'This address is already in use.');

            return;
        }

        MailAlias::create(['address' => $address, 'destination' => $this->aliasDestination]);
        $this->aliasLocal = '';
        $this->applyChanges('Alias added.');
    }

    public function removeAlias(int $id): void
    {
        MailAlias::whereKey($id)->delete();
        $this->applyChanges('Alias removed.');
    }

    public function hideRevealed(): void
    {
        $this->revealed = $this->revealedFor = null;
    }

    public function render()
    {
        return view('livewire.settings.mail-server', [
            'domains' => MailDomain::query()->orderBy('id')->get(),
            'aliases' => MailAlias::query()->orderBy('address')->get(),
            'hostname' => app(Server::class)->hostname(),
            'ports' => Server::PORTS,
        ]);
    }

    private function applyChanges(?string $message = null): void
    {
        try {
            if (MailDomain::exists()) {
                app(Server::class)->apply();
            }
            if ($message) {
                $this->dispatch('success', $message);
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
        $this->refreshState();
    }

    private function reveal(string $email, string $password): void
    {
        $this->revealedFor = $email;
        $this->revealed = $password;
    }
}
