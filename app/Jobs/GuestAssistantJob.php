<?php

namespace App\Jobs;

use App\Models\AiSettings;
use App\Services\MeharBot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Fork-only: Yogii chats with someone who messages the owner's number, and passes every message on to the owner.
 * Without the local model it falls back to fixed replies: ask who they are, then confirm it was passed on.
 * The conversation starts again after 12 quiet hours.
 */
class GuestAssistantJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    public $timeout = 180;

    private const PROMPT = 'You are Yogii, the personal assistant answering WhatsApp messages on behalf of the owner of this number, who cannot reply right now. '
        .'Chat naturally and warmly, and reply in the language and style the person writes in (Hindi, Hinglish or English), short like a WhatsApp message. '
        .'Answer greetings and general questions yourself. Find out politely who they are and what they need, and tell them you will pass it on. '
        .'You are not the owner: never pretend to be them, never promise anything on their behalf (money, meetings, prices, deadlines), '
        .'and never share their personal details, location, schedule or other contacts.';

    public function __construct(public string $number, public string $text)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $key = 'whatsapp-guest:'.$this->number;
        $chat = is_array($saved = Cache::get($key)) ? $saved : []; // older entries hold a step name
        $first = $chat === [];

        $owner = AiSettings::numbers(AiSettings::current()->whatsapp_root_numbers)[0] ?? null;
        if ($owner) {
            SendWhatsappMessageJob::dispatch($owner, "Message for you from +{$this->number} (wa.me/{$this->number}):\n\n".mb_substr($this->text, 0, 3000));
        }

        $text = mb_substr($this->text, 0, 1000);
        $reply = trim((string) MeharBot::yogii([['role' => 'system', 'content' => self::PROMPT], ...$chat, ['role' => 'user', 'content' => $text]], ['num_predict' => 300]));
        if ($reply === '') {
            $reply = $first
                ? "Hi! I'm Yogii, the personal assistant for this number. They can't reply right now.\n\nMay I know your name and what you're messaging about? I'll pass it on."
                : (count($chat) === 2 ? "Thanks! I've passed this on. You'll get a reply as soon as possible." : null);
        }
        if ($first && MeharBot::enabled()) {
            $reply .= "\n\nMehar Advisory partner? Reply *login* to use the dashboard here.";
        }

        // Only the last two exchanges: the small local model copies long histories instead of answering.
        Cache::put($key, array_slice([...$chat, ['role' => 'user', 'content' => $text], ['role' => 'assistant', 'content' => mb_substr((string) $reply, 0, 1000)]], -4), now()->addHours(12));
        if ($reply !== null) {
            SendWhatsappMessageJob::dispatch($this->number, $reply);
        }
    }
}
