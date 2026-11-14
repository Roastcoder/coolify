<?php

namespace App\Jobs;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\AiAssistant;
use App\Services\WhatsappGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fork-only: answers one WhatsApp message with the AI assistant. Encrypted because the text can hold the access password.
 */
class AiWhatsappReplyJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A retry could repeat an action the user allowed, so it runs once. */
    public $tries = 1;

    public $timeout = 900;

    public function __construct(public int $userId, public string $number, public string $text, public bool $readOnly = true)
    {
        $this->onQueue('high');
    }

    public function handle(AiAssistant $assistant, WhatsappGateway $gateway): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $conversation = AiConversation::query()->firstOrNew(['channel' => 'whatsapp', 'channel_id' => $this->number], ['messages' => []]);
        // A number can move between root and read-only, or to another user: start over then.
        if ($conversation->exists && ($conversation->user_id !== $user->id || $conversation->read_only !== $this->readOnly)) {
            $conversation->fill(['messages' => [], 'pending_action' => null, 'unlocked_until' => null]);
        }
        $conversation->fill(['user_id' => $user->id, 'read_only' => $this->readOnly])->save();

        try {
            $reply = $assistant->handle($conversation, $this->text);
        } catch (\Throwable $e) {
            report($e);
            $reply = 'Something went wrong. Try again.';
        }

        $gateway->send($this->number, $reply);
    }
}
