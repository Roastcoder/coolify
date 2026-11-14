<?php

namespace App\Livewire\Ai;

use App\Jobs\AiWebTaskJob;
use App\Models\AiConversation;
use App\Services\AiAssistant;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Fork-only: the AI page, a chat with the assistant. Instance admins only; the web app needs no access password.
 */
class Index extends Component
{
    public string $message = '';

    /** @var array<int, array{role: string, text: string}> */
    #[Locked]
    public array $log = [];

    /** 'chat' while the page waits for AiWebTaskJob, else null. */
    #[Locked]
    public ?string $waiting = null;

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        $this->log = $this->transcript();
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

    public function newChat(): void
    {
        $this->conversation()->update(['messages' => [], 'pending_action' => null]);
        $this->log = [];
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

    private function conversation(): AiConversation
    {
        $user = auth()->user();

        return AiConversation::query()->firstOrCreate(['channel' => 'web', 'channel_id' => (string) $user->id], ['user_id' => $user->id, 'messages' => []]);
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
        ]);
    }
}
