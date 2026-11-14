<?php

namespace App\Livewire\Ai;

use App\Jobs\AiWebTaskJob;
use App\Models\AiConversation;
use App\Models\Server;
use App\Services\AiAssistant;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Fork-only: the AI page. A chat with the assistant and a code editor for files on a server,
 * with Claude to change them. Reading and writing files is root access, so it needs the page unlocked.
 */
class Index extends Component
{
    public string $message = '';

    /** @var array<int, array{role: string, text: string}> */
    #[Locked]
    public array $log = [];

    /** What the page waits for from AiWebTaskJob: 'chat', 'rewrite' or null. */
    #[Locked]
    public ?string $waiting = null;

    public string $unlockPassword = '';

    public string $serverUuid = '';

    public string $path = '';

    public string $content = '';

    public string $instruction = '';

    #[Locked]
    public ?string $loadedPath = null;

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        $this->log = $this->transcript();
        $this->serverUuid = (string) $this->servers()->first()?->uuid;
    }

    /** This page gives root on every server, so check on every request. */
    public function hydrate(): void
    {
        abort_unless(isInstanceAdmin(), 403);
    }

    public function send(): void
    {
        $text = trim($this->message);
        if ($text === '' || $this->waiting) {
            return;
        }
        $this->message = '';
        // Shown as typed, except the password.
        $this->log[] = ['role' => 'user', 'text' => preg_match('/^\/?unlock\s/i', $text) ? 'unlock ••••••••' : $text];
        $this->start('chat', ['text' => mb_substr($text, 0, 8000)]);
    }

    public function answer(bool $allow): void
    {
        $this->message = $allow ? 'YES' : 'NO';
        $this->send();
    }

    public function unlock(): void
    {
        $this->message = 'unlock '.$this->unlockPassword;
        $this->unlockPassword = '';
        $this->send();
    }

    public function newChat(): void
    {
        $this->conversation()->update(['messages' => [], 'pending_action' => null]);
        $this->log = [];
    }

    public function loadFile(): void
    {
        $this->rootAction(function (AiAssistant $assistant) {
            $this->validate(['path' => ['required', 'string', 'starts_with:/', 'max:1000']]);
            $this->content = $assistant->readServerFile(auth()->user(), $this->serverUuid, $this->path);
            $this->loadedPath = $this->path;
        });
    }

    public function saveFile(): void
    {
        $this->rootAction(function (AiAssistant $assistant) {
            $this->validate(['path' => ['required', 'string', 'starts_with:/', 'max:1000'], 'content' => ['string', 'max:1000000']]);
            $this->dispatch('success', $assistant->writeServerFile(auth()->user(), $this->serverUuid, $this->path, $this->content));
            $this->loadedPath = $this->path;
        });
    }

    public function askClaude(): void
    {
        $this->validate(['instruction' => ['required', 'string', 'max:4000'], 'path' => ['required', 'string']]);
        if ($this->waiting) {
            return;
        }
        $this->start('rewrite', ['path' => $this->path, 'content' => $this->content, 'instruction' => $this->instruction]);
    }

    /** Polled while waiting for AiWebTaskJob. */
    public function checkResult(): void
    {
        if (! $this->waiting) {
            return;
        }
        $result = Cache::pull(AiWebTaskJob::resultKey($this->conversation()->id));
        if (! $result) {
            return;
        }
        $this->waiting = null;
        if (isset($result['error'])) {
            $this->dispatch('error', $result['error']);
        } elseif ($result['kind'] === 'rewrite') {
            $this->content = $result['content'];
            $this->instruction = '';
            $this->dispatch('success', 'Claude changed the file. Review it, then Save.');
        } else {
            $this->log[] = ['role' => 'assistant', 'text' => $result['text']];
        }
    }

    private function start(string $kind, array $payload): void
    {
        $conversation = $this->conversation();
        Cache::forget(AiWebTaskJob::resultKey($conversation->id));
        $this->waiting = $kind;
        AiWebTaskJob::dispatch($conversation->id, $kind, $payload);
        $this->checkResult();
    }

    private function rootAction(callable $action): void
    {
        if (! $this->conversation()->isUnlocked()) {
            $this->dispatch('error', 'Root access is locked. Enter the access password to unlock it.');

            return;
        }
        try {
            $action(app(AiAssistant::class));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function conversation(): AiConversation
    {
        $user = auth()->user();

        return AiConversation::query()->firstOrCreate(['channel' => 'web', 'channel_id' => (string) $user->id], ['user_id' => $user->id, 'messages' => []]);
    }

    private function servers()
    {
        return Server::query()->whereIn('team_id', auth()->user()->teams()->pluck('teams.id'))->orderBy('name')->get(['id', 'uuid', 'name', 'ip']);
    }

    /** @return array<int, array{role: string, text: string}> */
    private function transcript(): array
    {
        $log = [];
        foreach ($this->conversation()->messages ?? [] as $message) {
            $text = is_string($message['content'])
                ? $message['content']
                : collect($message['content'])->where('type', 'text')->pluck('text')->implode("\n\n");
            if (trim($text) !== '') {
                $log[] = ['role' => $message['role'], 'text' => $text];
            }
        }

        return $log;
    }

    public function render()
    {
        $conversation = $this->conversation();

        return view('livewire.ai.index', [
            'configured' => app(AiAssistant::class)->isConfigured(),
            'pending' => $conversation->pending_action ? app(AiAssistant::class)->describe($conversation->pending_action['calls']) : null,
            'unlocked' => $conversation->isUnlocked(),
            'servers' => $this->servers()->map(fn (Server $server) => ['value' => $server->uuid, 'label' => $server->name.' ('.$server->ip.')'])->all(),
        ]);
    }
}
