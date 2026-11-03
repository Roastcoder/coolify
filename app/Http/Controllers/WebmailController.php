<?php

namespace App\Http\Controllers;

use App\Services\MailServer;
use App\Services\Webmail\Mailbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fork-only: StandaloneCoders Mail, the webmail for mailboxes on the built-in mail server.
 * The mailbox password lives encrypted in the session, and in an encrypted cookie for "Remember me".
 */
class WebmailController extends Controller
{
    private const SESSION_KEY = 'webmail';

    private const REMEMBER_COOKIE = 'sc_webmail';

    private const REMEMBER_MINUTES = 60 * 24 * 30;

    public function showLogin(Request $request)
    {
        return $this->credentials($request) ? redirect()->route('webmail.index') : view('webmail.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
        ]);
        $credentials['email'] = Str::lower(trim($credentials['email']));

        $key = 'webmail-login:'.$credentials['email'].'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in a minute.']);
        }
        if (! app(Mailbox::class, $credentials)->check()) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'Wrong email or password.']);
        }
        RateLimiter::clear($key);

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, Crypt::encryptString(json_encode($credentials)));
        if ($request->boolean('remember')) {
            // The cookie is encrypted by the web middleware, and only sent over HTTPS to this app.
            Cookie::queue(Cookie::make(self::REMEMBER_COOKIE, json_encode($credentials), self::REMEMBER_MINUTES, secure: $request->isSecure(), httpOnly: true, sameSite: 'lax'));
        }

        return redirect()->route('webmail.index');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget(self::REMEMBER_COOKIE));

        return redirect()->route('webmail.login');
    }

    public function index(Request $request)
    {
        $mailbox = $this->mailbox($request);
        if (! $mailbox) {
            return redirect()->route('webmail.login');
        }

        try {
            $folders = $mailbox->folders();
        } catch (\Throwable) {
            // The password changed or the server is unreachable: sign in again.
            $this->logout($request);

            return redirect()->route('webmail.login')->withErrors(['email' => 'Please sign in again.']);
        }

        $folder = $this->folderPath($folders, $request->query('folder'));
        $search = Str::limit(trim((string) $request->query('q')), 100, '');
        $page = max(1, (int) $request->query('page', 1));
        $list = $mailbox->messages($folder, $page, $search);
        $uid = (int) $request->query('uid');
        $message = $uid > 0 ? $mailbox->message($folder, $uid) : null;

        try {
            $mailHostname = app(MailServer::class)->hostname() ?: $request->getHost();
        } catch (\Throwable) {
            $mailHostname = $request->getHost();
        }

        return view('webmail.index', [
            'email' => $mailbox->email,
            'mailHostname' => $mailHostname,
            'folders' => $folders,
            'folder' => $folder,
            'role' => collect($folders)->firstWhere('path', $folder)['role'] ?? null,
            'search' => $search,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / Mailbox::PAGE_SIZE)),
            'total' => $list['total'],
            'messages' => $list['items'],
            'message' => $message,
        ]);
    }

    public function update(Request $request, int $uid): RedirectResponse
    {
        $mailbox = $this->mailbox($request);
        if (! $mailbox) {
            return redirect()->route('webmail.login');
        }

        $data = $request->validate(['action' => ['required', 'in:read,unread,archive,unarchive,delete']]);
        $folders = $mailbox->folders();
        $folder = $this->folderPath($folders, $request->input('folder'));
        $roles = collect($folders)->pluck('path', 'role');
        $inboxPath = $roles->get('inbox') ?: 'INBOX';

        match ($data['action']) {
            'read' => $mailbox->setSeen($folder, $uid, true),
            'unread' => $mailbox->setSeen($folder, $uid, false),
            'archive' => $roles->get('archive') && $mailbox->move($folder, $uid, $roles->get('archive')),
            'unarchive' => $inboxPath && $mailbox->move($folder, $uid, $inboxPath),
            'delete' => $mailbox->delete($folder, $uid, $roles->get('trash')),
        };

        // Opening a message marks it read, so "unread", "archive", or "unarchive" goes back to the list.
        $keep = $data['action'] === 'read' ? ['uid' => $uid] : [];

        return redirect()->route('webmail.index', array_filter([
            'folder' => $folder,
            'q' => $request->input('q'),
            'page' => $request->input('page'),
            ...$keep,
        ]))->with('status', match ($data['action']) {
            'archive' => 'Archived.',
            'unarchive' => 'Moved to Inbox.',
            'delete' => 'Deleted.',
            default => null,
        });
    }

    public function updatePassword(Request $request, MailServer $mail): RedirectResponse
    {
        $mailbox = $this->mailbox($request);
        if (! $mailbox) {
            return redirect()->route('webmail.login');
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'max:128', 'confirmed'],
        ]);

        if (! $mail->checkMailboxPassword($mailbox->email, $validated['current_password'])) {
            return back()->withErrors(['current_password' => 'The current password you entered is incorrect.']);
        }

        $mail->setPassword($mailbox->email, $validated['password']);

        $credentials = [
            'email' => $mailbox->email,
            'password' => $validated['password'],
        ];
        $request->session()->put(self::SESSION_KEY, Crypt::encryptString(json_encode($credentials)));
        if ($request->hasCookie(self::REMEMBER_COOKIE)) {
            Cookie::queue(Cookie::make(self::REMEMBER_COOKIE, json_encode($credentials), self::REMEMBER_MINUTES, secure: $request->isSecure(), httpOnly: true, sameSite: 'lax'));
        }

        return back()->with('status', 'Mailbox password updated successfully.');
    }

    public function send(Request $request): RedirectResponse
    {
        $mailbox = $this->mailbox($request);
        if (! $mailbox) {
            return redirect()->route('webmail.login');
        }

        $validated = $request->validate([
            'to' => ['required', 'string'],
            'cc' => ['nullable', 'string'],
            'bcc' => ['nullable', 'string'],
            'subject' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:25600'],
        ]);

        try {
            $mailbox->send(
                to: $validated['to'],
                subject: (string) ($validated['subject'] ?? ''),
                body: (string) ($validated['body'] ?? ''),
                cc: (string) ($validated['cc'] ?? ''),
                bcc: (string) ($validated['bcc'] ?? ''),
                attachments: $request->file('attachments') ?? []
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['to' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['send' => 'Could not send message: '.$e->getMessage()]);
        }

        return redirect()->route('webmail.index', array_filter([
            'folder' => $request->input('folder'),
            'q' => $request->input('q'),
            'page' => $request->input('page'),
        ]))->with('status', 'Message sent.');
    }

    public function attachment(Request $request, int $uid, int $index)
    {
        $mailbox = $this->mailbox($request);
        abort_unless($mailbox, 403);

        $folder = $this->folderPath($mailbox->folders(), $request->query('folder'));
        $file = $mailbox->attachment($folder, $uid, $index);
        abort_unless($file, 404);

        // Always a download, never rendered here, so an HTML attachment cannot run on this site.
        return response()->streamDownload(fn () => print ($file['content']), $file['name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{email: string, password: string}|null */
    private function credentials(Request $request): ?array
    {
        try {
            if ($stored = $request->session()->get(self::SESSION_KEY)) {
                return json_decode(Crypt::decryptString($stored), true);
            }
            if ($remembered = $request->cookie(self::REMEMBER_COOKIE)) {
                $credentials = json_decode($remembered, true);
                if (is_string($credentials['email'] ?? null) && is_string($credentials['password'] ?? null)) {
                    $request->session()->put(self::SESSION_KEY, Crypt::encryptString(json_encode($credentials)));

                    return $credentials;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function mailbox(Request $request): ?Mailbox
    {
        $credentials = $this->credentials($request);

        return $credentials ? app(Mailbox::class, ['email' => $credentials['email'], 'password' => $credentials['password']]) : null;
    }

    /** Only a folder the server listed, so a crafted path can't reach anything else; Inbox otherwise. */
    private function folderPath(array $folders, mixed $requested): string
    {
        $paths = array_column($folders, 'path');
        if (is_string($requested) && in_array($requested, $paths, true)) {
            return $requested;
        }

        return collect($folders)->firstWhere('role', 'inbox')['path'] ?? ($paths[0] ?? 'INBOX');
    }
}
