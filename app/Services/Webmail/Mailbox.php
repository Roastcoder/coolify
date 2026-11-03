<?php

namespace App\Services\Webmail;

use App\Services\MailServer;
use Illuminate\Support\Str;
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

            return [
                'uid' => (int) $message->getUid(),
                'from' => (string) ($from->personal ?? '') ?: (string) ($from->mail ?? ''),
                'from_email' => (string) ($from->mail ?? ''),
                'subject' => (string) $message->getSubject() ?: '(no subject)',
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
            'subject' => (string) $message->getSubject() ?: '(no subject)',
            'from' => (string) ($from->personal ?? '') ?: (string) ($from->mail ?? ''),
            'from_email' => (string) ($from->mail ?? ''),
            'to' => $this->addresses($message->getTo()),
            'cc' => $this->addresses($message->getCc()),
            'date' => optional($message->getDate()->first())->toIso8601String(),
            'html' => $html,
            'attachments' => $attachments
                ->filter(fn ($attachment) => $attachment->disposition !== 'inline' || ! $attachment->id)
                ->map(fn ($attachment, $index) => [
                    'index' => $index,
                    'name' => (string) ($attachment->name ?: 'attachment'),
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
            ->map(fn ($address) => $address->personal ? "{$address->personal} <{$address->mail}>" : (string) $address->mail)
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
