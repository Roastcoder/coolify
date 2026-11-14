<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\AiWhatsappReplyJob;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\AiSettings;
use App\Models\User;
use App\Services\WhatsappGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Fork-only: incoming chats from the built-in WhatsApp gateway.
 * Root numbers (Settings > AI assistant) act as the instance owner; root actions still need the access password.
 * Other senders whose profile phone number matches a user get read-only dashboard data.
 * Everyone else talks to the owner's personal assistant, which asks what they want and passes it on to the owner.
 */
class Whatsapp extends Controller
{
    public function __invoke(Request $request, WhatsappGateway $gateway): JsonResponse
    {
        abort_unless($gateway->isValidSecret($request->header('X-Whatsapp-Secret')), 401);

        $number = preg_replace('/\D/', '', (string) $request->input('number'));
        $body = trim((string) $request->input('body'));
        $id = (string) $request->input('id');
        if (strlen($number) < 10 || $body === '' || $id === '' || ! Cache::add('whatsapp-message:'.$id, true, now()->addDay())) {
            return response()->json(['ok' => true]);
        }

        $root = AiSettings::current()->isRootNumber($number);
        $user = $root ? self::owner($number) : self::userForNumber($number);
        if ($user) {
            AiWhatsappReplyJob::dispatch($user->id, $number, mb_substr($body, 0, 8000), readOnly: ! $root);
        } else {
            self::personalAssistant($number, $body);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Instant replies, no AI: asks a new sender what they want, passes their answer and later messages to the owner.
     * The conversation starts again after 12 quiet hours.
     */
    private static function personalAssistant(string $number, string $body): void
    {
        $owner = AiSettings::numbers(AiSettings::current()->whatsapp_root_numbers)[0] ?? null;
        $key = 'whatsapp-guest:'.$number;
        $step = Cache::get($key);
        Cache::put($key, $step === null ? 'asked' : 'relayed', now()->addHours(12));

        if ($step === null) {
            SendWhatsappMessageJob::dispatch($number, "Hi! I'm the personal assistant for this number. They can't reply right now.\n\nMay I know your name and what you're messaging about? I'll pass it on.");

            return;
        }
        if ($owner) {
            SendWhatsappMessageJob::dispatch($owner, "Message for you from +{$number} (wa.me/{$number}):\n\n".mb_substr($body, 0, 3000));
        }
        if ($step === 'asked') {
            SendWhatsappMessageJob::dispatch($number, "Thanks! I've passed this on. You'll get a reply as soon as possible.");
        }
    }

    /** The instance admin with this phone number, else the root user. */
    private static function owner(string $number): ?User
    {
        $user = self::userForNumber($number);

        return $user?->isInstanceAdmin() ? $user : User::find(0);
    }

    /** Matches on the last 10 digits, so +91 98765 43210 and 9876543210 are the same number. */
    public static function userForNumber(string $number): ?User
    {
        $last10 = substr($number, -10);

        return User::query()->whereNotNull('phone')->get()
            ->first(fn (User $user) => substr(preg_replace('/\D/', '', (string) $user->phone), -10) === $last10);
    }
}
