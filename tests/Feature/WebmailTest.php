<?php

use App\Services\Webmail\Mailbox;
use Illuminate\Http\UploadedFile;

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
            'text' => 'Hello',
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

    public function send(array|string $to, string $subject, string $body, array|string $cc = [], array|string $bcc = [], array $attachments = []): void
    {
        self::$calls[] = ['send', $to, $subject, $body, $cc, $bcc, $attachments];
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

it('sends an email from webmail', function () {
    signInToWebmail($this);

    $this->post(route('webmail.send'), [
        'to' => 'client@example.com',
        'subject' => 'Meeting notes',
        'body' => 'Here are the notes from our call.',
    ])->assertRedirect(route('webmail.index'))
        ->assertSessionHas('status', 'Message sent.');

    expect(FakeMailbox::$calls)->toHaveCount(1);
    expect(FakeMailbox::$calls[0][0])->toBe('send');
    expect(FakeMailbox::$calls[0][1])->toBe('client@example.com');
    expect(FakeMailbox::$calls[0][2])->toBe('Meeting notes');
    expect(FakeMailbox::$calls[0][3])->toBe('Here are the notes from our call.');
});

it('sends an email with cc, bcc and attachments', function () {
    signInToWebmail($this);

    $file = UploadedFile::fake()->create('report.pdf', 50, 'application/pdf');

    $this->post(route('webmail.send'), [
        'to' => 'client@example.com',
        'cc' => 'boss@example.com',
        'bcc' => 'archive@example.com',
        'subject' => 'Quarterly Report',
        'body' => 'Please find attached the report.',
        'attachments' => [$file],
    ])->assertRedirect(route('webmail.index'))
        ->assertSessionHas('status', 'Message sent.');

    expect(FakeMailbox::$calls)->toHaveCount(1);
    expect(FakeMailbox::$calls[0][0])->toBe('send');
    expect(FakeMailbox::$calls[0][4])->toBe('boss@example.com');
    expect(FakeMailbox::$calls[0][5])->toBe('archive@example.com');
    expect(FakeMailbox::$calls[0][6])->toHaveCount(1);
});

it('requires a recipient to send an email', function () {
    signInToWebmail($this);

    $this->post(route('webmail.send'), [
        'to' => '',
        'subject' => 'Hello',
        'body' => 'World',
    ])->assertSessionHasErrors('to');

    expect(FakeMailbox::$calls)->toBeEmpty();
});

it('rejects an invalid email address when sending', function () {
    $mailbox = new Mailbox('info@example.com', 'password');

    expect(fn () => $mailbox->parseAddresses('not-an-email'))
        ->toThrow(InvalidArgumentException::class, 'Invalid email address: not-an-email');
});

it('parses multiple valid email addresses', function () {
    $mailbox = new Mailbox('info@example.com', 'password');

    $addresses = $mailbox->parseAddresses('Alice <alice@example.com>, bob@example.com, "Charlie, Jr." <charlie@example.com>');
    expect($addresses)->toHaveCount(3);
    expect($addresses[0]->getAddress())->toBe('alice@example.com');
    expect($addresses[0]->getName())->toBe('Alice');
    expect($addresses[1]->getAddress())->toBe('bob@example.com');
    expect($addresses[2]->getAddress())->toBe('charlie@example.com');
    expect($addresses[2]->getName())->toBe('Charlie, Jr.');
});

it('shows new message, reply and forward controls in webmail', function () {
    signInToWebmail($this);

    $this->get(route('webmail.index'))
        ->assertOk()
        ->assertSee('New message')
        ->assertSee('compose-dialog');

    $this->get(route('webmail.index', ['uid' => 7]))
        ->assertOk()
        ->assertSee('Reply')
        ->assertSee('Forward')
        ->assertSee('inline-composer-box');
});

it('decodes MIME encoded subjects and sender headers', function () {
    expect(Mailbox::decodeMimeHeader('=?UTF-8?Q?Re=3A_Coolify_Platform_=E2=80=93_New_Updates_and_Improvement?= =?UTF-8?Q?s?='))
        ->toBe('Re: Coolify Platform – New Updates and Improvements');

    expect(Mailbox::decodeMimeHeader('=?UTF-8?B?WW9nZW5kcmEgRmF1amRhcg==?='))
        ->toBe('Yogendra Faujdar');

    expect(Mailbox::decodeMimeHeader('Plain Subject'))
        ->toBe('Plain Subject');
});

it('unarchives a message by moving it to the inbox', function () {
    signInToWebmail($this);

    $this->post(route('webmail.messages.update', 7), ['action' => 'unarchive', 'folder' => 'Archive'])
        ->assertRedirect()
        ->assertSessionHas('status', 'Moved to Inbox.');

    expect(FakeMailbox::$calls)->toBe([
        ['move', 'Archive', 7, 'INBOX'],
    ]);
});

it('shows move to inbox unarchive button when in archive folder', function () {
    signInToWebmail($this);

    $this->get(route('webmail.index', ['folder' => 'Archive', 'uid' => 7]))
        ->assertOk()
        ->assertSee('Move to Inbox');
});
