<?php

namespace App\Services\Webmail;

use App\Services\MailServer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Fork-only: one signed-in mailbox of the built-in mail server, read over IMAP for StandaloneCoders Mail.
 * Create it with app(Mailbox::class, ['email' => ..., 'password' => ...]) so tests can swap it.
 */
class Mailbox
{
    public const PAGE_SIZE = 50;

    /** Folder roles, matched against folder names, in the order the folder list shows them. */
    private const ROLES = [
        'inbox' => ['inbox'],
        'drafts' => ['drafts', 'draft'],
        'sent' => ['sent', 'sent items', 'sent messages'],
        'archive' => ['archive', 'archives'],
        'junk' => ['junk', 'spam', 'junk e-mail'],
        'trash' => ['trash', 'deleted items', 'deleted messages', 'bin'],
    ];

    private ?Client $client = null;

    public function __construct(public readonly string $email, private readonly string $password) {}

    /** True when the email and password sign in to the mail server. */
    public function check(): bool
    {
        try {
            $this->client();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int, array{path: string, name: string, role: ?string, unread: int}> */
    public function folders(): array
    {
        $folders = [];
        foreach ($this->client()->getFolders(false) as $folder) {
            /** @var Folder $folder */
            $role = $this->role($folder->name);
            $folders[] = [
                'path' => $folder->path,
                'name' => $role === 'inbox' ? 'Inbox' : $folder->name,
                'role' => $role,
                'unread' => $role === 'sent' || $role === 'drafts' ? 0 : (int) ($folder->status()['unseen'] ?? 0),
            ];
        }

        $order = array_keys(self::ROLES);
        usort($folders, fn (array $a, array $b) => [array_search($a['role'], $order, true) === false ? 99 : array_search($a['role'], $order, true), $a['name']]
            <=> [array_search($b['role'], $order, true) === false ? 99 : array_search($b['role'], $order, true), $b['name']]);

        return $folders;
    }

    /**
     * Decodes MIME-encoded header strings (RFC 2047), e.g. =?UTF-8?Q?...?= or =?UTF-8?B?...?=
     */
    public static function decodeMimeHeader(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        if (! str_contains($text, '=?')) {
            return trim($text);
        }

        // Try iconv_mime_decode first
        $decoded = @iconv_mime_decode($text, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded !== false && ! str_contains($decoded, '=?')) {
            return trim($decoded);
        }

        // Fallback to mb_decode_mimeheader
        $mbDecoded = @mb_decode_mimeheader($text);
        if ($mbDecoded !== false && ! str_contains($mbDecoded, '=?')) {
            return trim($mbDecoded);
        }

        if (function_exists('imap_utf8')) {
            $imapDecoded = @imap_utf8($text);
            if ($imapDecoded && ! str_contains($imapDecoded, '=?')) {
                return trim($imapDecoded);
            }
        }

        return trim($decoded !== false ? $decoded : ($mbDecoded !== false ? $mbDecoded : $text));
    }

    /**
     * Newest first, without bodies.
     *
     * @return array{total: int, items: array<int, array{uid: int, from: string, from_email: string, subject: string, date: ?string, seen: bool, flagged: bool}>}
     */
    public function messages(string $folderPath, int $page = 1, string $search = ''): array
    {
        $query = $this->folder($folderPath)->messages()->setFetchBody(false)->setFetchOrder('desc')->leaveUnread();
        $query = $search !== '' ? $query->whereText($search) : $query->all();

        $total = (clone $query)->count();
        $items = $query->limit(self::PAGE_SIZE, max(1, $page))->get()->map(function (Message $message) {
            $from = $message->getFrom()->first();
            $flags = $message->getFlags()->map(fn ($flag) => strtolower((string) $flag))->values()->all();
            $rawPersonal = (string) ($from->personal ?? '');
            $fromName = static::decodeMimeHeader($rawPersonal) ?: (string) ($from->mail ?? '');

            return [
                'uid' => (int) $message->getUid(),
                'from' => $fromName,
                'from_email' => (string) ($from->mail ?? ''),
                'subject' => static::decodeMimeHeader((string) $message->getSubject()) ?: '(no subject)',
                'date' => optional($message->getDate()->first())->toIso8601String(),
                'seen' => in_array('seen', $flags, true),
                'flagged' => in_array('flagged', $flags, true),
            ];
        })->values()->all();

        return ['total' => $total, 'items' => $items];
    }

    /**
     * Opens a message and marks it read. The HTML has inline images embedded and still needs a sandboxed frame.
     *
     * @return array{uid: int, subject: string, from: string, from_email: string, to: string, cc: string, date: ?string, html: string, attachments: array<int, array{index: int, name: string, size: int, mime: string}>}|null
     */
    public function message(string $folderPath, int $uid): ?array
    {
        $message = $this->find($folderPath, $uid);
        if (! $message) {
            return null;
        }

        $message->setFlag('Seen');
        $from = $message->getFrom()->first();
        $attachments = $message->getAttachments()->values();
        $rawPersonal = (string) ($from->personal ?? '');
        $fromName = static::decodeMimeHeader($rawPersonal) ?: (string) ($from->mail ?? '');

        if ($message->hasHTMLBody()) {
            $html = $message->getHTMLBody();
            foreach ($attachments as $attachment) {
                if ($attachment->id) {
                    $html = str_replace('cid:'.trim($attachment->id, '<>'), 'data:'.$attachment->getMimeType().';base64,'.base64_encode($attachment->content), $html);
                }
            }
        } else {
            $html = '<pre style="white-space:pre-wrap;font:inherit;margin:0">'.e($message->getTextBody()).'</pre>';
        }

        return [
            'uid' => $uid,
            'subject' => static::decodeMimeHeader((string) $message->getSubject()) ?: '(no subject)',
            'from' => $fromName,
            'from_email' => (string) ($from->mail ?? ''),
            'to' => $this->addresses($message->getTo()),
            'cc' => $this->addresses($message->getCc()),
            'date' => optional($message->getDate()->first())->toIso8601String(),
            'html' => $html,
            'text' => (string) ($message->getTextBody() ?? strip_tags($message->getHTMLBody() ?? '')),
            'attachments' => $attachments
                ->filter(fn ($attachment) => $attachment->disposition !== 'inline' || ! $attachment->id)
                ->map(fn ($attachment, $index) => [
                    'index' => $index,
                    'name' => static::decodeMimeHeader((string) ($attachment->name ?: 'attachment')),
                    'size' => (int) $attachment->size,
                    'mime' => (string) $attachment->getMimeType(),
                ])->values()->all(),
        ];
    }

    /** @return array{name: string, mime: string, content: string}|null */
    public function attachment(string $folderPath, int $uid, int $index): ?array
    {
        $attachment = $this->find($folderPath, $uid)?->getAttachments()->values()->get($index);

        return $attachment ? [
            'name' => (string) ($attachment->name ?: 'attachment'),
            'mime' => (string) ($attachment->getMimeType() ?: 'application/octet-stream'),
            'content' => (string) $attachment->content,
        ] : null;
    }

    public function setSeen(string $folderPath, int $uid, bool $seen): void
    {
        $message = $this->find($folderPath, $uid);
        $seen ? $message?->setFlag('Seen') : $message?->unsetFlag('Seen');
    }

    public function move(string $folderPath, int $uid, string $targetPath): void
    {
        $this->find($folderPath, $uid)?->move($targetPath, true);
    }

    /** Moves the message to the trash folder, or deletes it for good when it is already there. */
    public function delete(string $folderPath, int $uid, ?string $trashPath): void
    {
        $message = $this->find($folderPath, $uid);
        if (! $message) {
            return;
        }

        $trashPath && $trashPath !== $folderPath ? $message->move($trashPath, true) : $message->delete(true);
    }

    /**
     * Sends an email via SMTP and copies it to the Sent folder over IMAP.
     *
     * @param  array<int, string>|string  $to
     * @param  array<int, string>|string  $cc
     * @param  array<int, string>|string  $bcc
     * @param  array<int, UploadedFile|array{name: string, mime: string, content: string}>  $attachments
     */
    public function send(
        array|string $to,
        string $subject,
        string $body,
        array|string $cc = [],
        array|string $bcc = [],
        array $attachments = []
    ): void {
        $toAddresses = $this->parseAddresses($to);
        if (empty($toAddresses)) {
            throw new InvalidArgumentException('Please specify at least one recipient.');
        }

        $email = (new Email)
            ->from($this->email)
            ->subject($subject !== '' ? $subject : '(no subject)');

        foreach ($toAddresses as $address) {
            $email->addTo($address);
        }
        foreach ($this->parseAddresses($cc) as $address) {
            $email->addCc($address);
        }
        foreach ($this->parseAddresses($bcc) as $address) {
            $email->addBcc($address);
        }

        $email->text($body);
        if (strip_tags($body) !== $body) {
            $email->html($body);
        } else {
            $email->html(nl2br(e($body)));
        }

        foreach ($attachments as $attachment) {
            if ($attachment instanceof UploadedFile) {
                $email->attachFromPath(
                    $attachment->getRealPath(),
                    $attachment->getClientOriginalName(),
                    $attachment->getClientMimeType()
                );
            } elseif (is_array($attachment) && isset($attachment['content'])) {
                $email->attach(
                    $attachment['content'],
                    $attachment['name'] ?? 'attachment',
                    $attachment['mime'] ?? 'application/octet-stream'
                );
            }
        }

        $this->mailer()->send($email);

        $this->saveToSentFolder($email);
    }

    /**
     * @param  array<int, string>|string  $addresses
     * @return array<int, Address>
     */
    public function parseAddresses(array|string $addresses): array
    {
        if (is_array($addresses)) {
            $strings = $addresses;
        } else {
            $strings = str_getcsv($addresses, ',', '"', '\\');
        }

        $parsed = [];
        foreach ($strings as $item) {
            $item = trim((string) $item);
            if ($item === '') {
                continue;
            }
            try {
                $parsed[] = Address::create($item);
            } catch (\Throwable) {
                throw new InvalidArgumentException("Invalid email address: {$item}");
            }
        }

        return $parsed;
    }

    protected function saveToSentFolder(Email $email): void
    {
        try {
            $folders = $this->folders();
            $sentPath = collect($folders)->firstWhere('role', 'sent')['path'] ?? null;
            if (! $sentPath) {
                $paths = array_column($folders, 'path');
                foreach (['Sent', 'INBOX.Sent', 'Sent Items', 'Sent Messages'] as $candidate) {
                    if (in_array($candidate, $paths, true)) {
                        $sentPath = $candidate;
                        break;
                    }
                }
            }

            if ($sentPath) {
                $this->folder($sentPath)->appendMessage($email->toString(), ['\\Seen']);
            }
        } catch (\Throwable) {
            // Appending to Sent is non-fatal: delivery via SMTP has already succeeded.
        }
    }

    protected function transport(): TransportInterface
    {
        $hostname = app(MailServer::class)->hostname() ?? 'localhost';
        $transport = new EsmtpTransport($hostname, 465, true);
        $transport->setUsername($this->email);
        $transport->setPassword($this->password);

        return $transport;
    }

    protected function mailer(): MailerInterface
    {
        return new Mailer($this->transport());
    }

    private function find(string $folderPath, int $uid): ?Message
    {
        try {
            return $this->folder($folderPath)->messages()->getMessageByUid($uid);
        } catch (\Throwable) {
            return null;
        }
    }

    private function folder(string $path): Folder
    {
        return $this->client()->getFolderByPath($path) ?? throw new \RuntimeException('Folder not found.');
    }

    private function role(string $name): ?string
    {
        $name = Str::lower(Str::afterLast($name, '/'));
        foreach (self::ROLES as $role => $names) {
            if (in_array($name, $names, true)) {
                return $role;
            }
        }

        return null;
    }

    private function addresses($attribute): string
    {
        return collect($attribute?->all() ?? [])
            ->map(function ($address) {
                $personal = static::decodeMimeHeader((string) ($address->personal ?? ''));
                $mail = (string) ($address->mail ?? '');

                return $personal !== '' ? "{$personal} <{$mail}>" : $mail;
            })
            ->filter()
            ->implode(', ');
    }

    private function client(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        $client = (new ClientManager)->make([
            'host' => app(MailServer::class)->hostname(),
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'protocol' => 'imap',
            'username' => $this->email,
            'password' => $this->password,
            'timeout' => 15,
        ]);
        $client->connect();

        return $this->client = $client;
    }
}
