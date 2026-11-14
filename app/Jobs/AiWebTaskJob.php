<?php

namespace App\Jobs;

use App\Models\AiConversation;
use App\Services\AiAssistant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Fork-only: runs one request of the AI page in the background (Claude can take minutes) and leaves
 * the result in the cache, where App\Livewire\Ai\Index picks it up.
 */
class AiWebTaskJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A retry could repeat an action the user allowed, so it runs once. */
    public $tries = 1;

    public $timeout = 900;

    /** @param  array<string, string>  $payload */
    public function __construct(public int $conversationId, public string $kind, public array $payload)
    {
        $this->onQueue('high');
    }

    public static function resultKey(int $conversationId): string
    {
        return "ai-web-result:{$conversationId}";
    }

    public function handle(AiAssistant $assistant): void
    {
        $conversation = AiConversation::query()->with('user')->find($this->conversationId);
        if (! $conversation?->user?->isInstanceAdmin()) {
            return;
        }

        try {
            $result = ['kind' => 'chat', 'text' => $assistant->handle($conversation, $this->payload['text'])];
        } catch (\Throwable $e) {
            report($e);
            $result = ['kind' => $this->kind, 'error' => $e->getMessage()];
        }

        Cache::put(self::resultKey($this->conversationId), $result, now()->addHour());
    }
}
