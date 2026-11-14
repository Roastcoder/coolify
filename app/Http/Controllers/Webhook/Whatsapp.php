<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\AiWhatsappReplyJob;
use App\Jobs\GuestAssistantJob;
use App\Jobs\MeharBotJob;
use App\Models\AiSettings;
use App\Models\User;
use App\Services\MeharBot;
use App\Services\WhatsappGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Fork-only: incoming chats from the built-in WhatsApp gateway.
 * Root numbers (Settings > AI assistant) act as the instance owner; root actions still need the access password.
 * Other senders whose profile phone number matches a user get read-only dashboard data.
 * Everyone else chats with the owner's personal assistant (GuestAssistantJob), which passes their messages on to the owner.
 */
class Whatsapp extends Controller
{
    public function __invoke(Request $request, WhatsappGateway $gateway): JsonResponse
    {
        abort_unless($gateway->isValidSecret($request->header('X-Whatsapp-Secret')), 401);

        $number = preg_replace('/\D/', '', (string) $request->input('number'));
        $body = trim((string) $request->input('body'));
        $id = (string) $request->input('id');
        $media = is_array($request->input('media')) ? $request->input('media') : null;
        if (strlen($number) < 10 || ($body === '' && ! $media) || $id === '' || ! Cache::add('whatsapp-message:'.$id, true, now()->addDay())) {
            return response()->json(['ok' => true]);
        }

        // Mehar Advisory dashboard users: "login" starts the bot, and it keeps the chat while the session lasts.
        if (MeharBot::wants($number, $body)) {
            MeharBotJob::dispatch($number, mb_substr($body, 0, 2000), $media ? array_intersect_key($media, array_flip(['id', 'mimetype', 'filename', 'size'])) : null);

            return response()->json(['ok' => true]);
        }
        if ($body === '') {
            return response()->json(['ok' => true]);
        }

        $root = AiSettings::current()->isRootNumber($number);
        $user = $root ? self::owner($number) : self::userForNumber($number);
        if ($user) {
            AiWhatsappReplyJob::dispatch($user->id, $number, mb_substr($body, 0, 8000), readOnly: ! $root);
        } else {
            GuestAssistantJob::dispatch($number, mb_substr($body, 0, 3000));
        }

        return response()->json(['ok' => true]);
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
