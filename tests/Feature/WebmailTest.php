<?php

use App\Services\Webmail\Mailbox;

class FakeMailbox extends Mailbox
{
    public static string $password = 'right-password';

    public static array $calls = [];

    public function __construct(string $email, private readonly string $given)
    {
        parent::__construct($email, $given);
    }

    public function check(): bool
    {
        return $this->given === self::$password;
    }

    public function folders(): array
    {
        return [
            ['path' => 'INBOX', 'name' => 'Inbox', 'role' => 'inbox', 'unread' => 2],
            ['path' => 'Archive', 'name' => 'Archive', 'role' => 'archive', 'unread' => 0],
            ['path' => 'Trash', 'name' => 'Trash', 'role' => 'trash', 'unread' => 0],
        ];
    }

    public function messages(string $folderPath, int $page = 1, string $search = ''): array
    {
        self::$calls[] = ['messages', $folderPath, $page, $search];

        return ['total' => 1, 'items' => [
            ['uid' => 7, 'from' => 'Asha Rao', 'from_email' => 'asha@example.com', 'subject' => 'Quarterly invoice', 'date' => now()->toIso8601String(), 'seen' => false, 'flagged' => false],
        ]];
    }

    public function message(string $folderPath, int $uid): ?array
    {
        return $uid !== 7 ? null : [
            'uid' => 7, 'subject' => 'Quarterly invoice', 'from' => 'Asha Rao', 'from_email' => 'asha@example.com',
            'to' => 'info@example.com', 'cc' => '', 'date' => now()->toIso8601String(),
            'html' => '<p>Hello</p><script>alert(1)</script>',
            'attachments' => [['index' => 0, 'name' => 'invoice.pdf', 'size' => 2048, 'mime' => 'application/pdf']],
        ];
    }

    public function attachment(string $folderPath, int $uid, int $index): ?array
    {
        return $uid === 7 && $index === 0 ? ['name' => 'invoice.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4'] : null;
    }

    public function delete(string $folderPath, int $uid, ?string $trashPath): void
    {
        self::$calls[] = ['delete', $folderPath, $uid, $trashPath];
    }

    public function move(string $folderPath, int $uid, string $targetPath): void
    {
        self::$calls[] = ['move', $folderPath, $uid, $targetPath];
    }
}

beforeEach(function () {
    FakeMailbox::$calls = [];
    $this->app->bind(Mailbox::class, fn ($app, array $params) => new FakeMailbox($params['email'], $params['password']));
});

function signInToWebmail($test, bool $remember = false)
{
    return $test->post(route('webmail.login.submit'), ['email' => 'Info@Example.com', 'password' => 'right-password', 'remember' => $remember ? '1' : null]);
}

it('shows the sign-in page with remember me', function () {
    $this->get(route('webmail.login'))->assertOk()->assertSee('Sign in')->assertSee('Remember me for 30 days');
});

it('sends guests to the sign-in page', function () {
    $this->get(route('webmail.index'))->assertRedirect(route('webmail.login'));
});

it('rejects a wrong password', function () {
    $this->post(route('webmail.login.submit'), ['email' => 'info@example.com', 'password' => 'nope'])
        ->assertSessionHasErrors(['email' => 'Wrong email or password.']);
    $this->get(route('webmail.index'))->assertRedirect(route('webmail.login'));
});

it('signs in and lists the inbox', function () {
    signInToWebmail($this)->assertRedirect(route('webmail.index'))->assertCookieMissing('sc_webmail');

    $this->get(route('webmail.index'))
        ->assertOk()
        ->assertSee('info@example.com')
        ->assertSee('Quarterly invoice')
        ->assertSee('Archive');
    expect(FakeMailbox::$calls[0])->toBe(['messages', 'INBOX', 1, '']);
});

it('remembers the sign-in in a cookie when asked', function () {
    signInToWebmail($this, remember: true)->assertCookie('sc_webmail');
});

it('only opens folders the server listed', function () {
    signInToWebmail($this);

    $this->get(route('webmail.index', ['folder' => '../../etc', 'q' => 'invoice']))->assertOk();
    expect(FakeMailbox::$calls[0])->toBe(['messages', 'INBOX', 1, 'invoice']);
});

it('shows a message in a sandboxed frame without scripts', function () {
    signInToWebmail($this);

    $html = $this->get(route('webmail.index', ['uid' => 7]))->assertOk()->assertSee('invoice.pdf')->getContent();
    expect($html)->toContain('sandbox="allow-popups allow-popups-to-escape-sandbox"')
        ->not->toContain('allow-scripts')
        ->not->toContain('<script>alert(1)</script>');
});

it('moves deleted mail to the trash and archives to the archive', function () {
    signInToWebmail($this);

    $this->post(route('webmail.messages.update', 7), ['action' => 'delete', 'folder' => 'INBOX'])->assertRedirect();
    $this->post(route('webmail.messages.update', 7), ['action' => 'archive', 'folder' => 'INBOX'])->assertRedirect();

    expect(FakeMailbox::$calls)->toBe([
        ['delete', 'INBOX', 7, 'Trash'],
        ['move', 'INBOX', 7, 'Archive'],
    ]);
});

it('downloads attachments instead of showing them', function () {
    signInToWebmail($this);

    $this->get(route('webmail.attachment', ['uid' => 7, 'index' => 0, 'folder' => 'INBOX']))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=invoice.pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('signs out and forgets the mailbox', function () {
    signInToWebmail($this);

    $this->post(route('webmail.logout'))->assertRedirect(route('webmail.login'));
    $this->get(route('webmail.index'))->assertRedirect(route('webmail.login'));
});
