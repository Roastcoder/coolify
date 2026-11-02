<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fork-only: a one-time code sent by SMS as the second login step after a correct password.
 * The pending login and a hash of the code live in the session, never the code itself.
 */
class SmsLoginOtp
{
    public const TTL_SECONDS = 300;

    public const MAX_ATTEMPTS = 5;

    public static function isConfigured(): bool
    {
        return filled(config('services.sms.user')) && filled(config('services.sms.password'));
    }

    public static function appliesTo(?User $user): bool
    {
        return self::isConfigured() && filled($user?->phone);
    }

    /**
     * Start (or restart) the SMS step for a user whose password was just verified.
     */
    public static function start(Request $request, User $user, bool $remember): void
    {
        $code = (string) random_int(100000, 999999);

        self::send($user->phone, $code);

        $request->session()->put('sms_login', [
            'id' => $user->getKey(),
            'remember' => $remember,
            'hash' => Hash::make($code),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
            'attempts' => 0,
        ]);
    }

    /**
     * Returns the pending user when the code is right; null otherwise. Five wrong codes or an
     * expired code end the pending login, so the user has to enter the password again.
     */
    public static function verify(Request $request, string $code): ?User
    {
        $pending = $request->session()->get('sms_login');
        if (! $pending || now()->getTimestamp() > $pending['expires_at'] || $pending['attempts'] >= self::MAX_ATTEMPTS) {
            $request->session()->forget('sms_login');

            return null;
        }

        if (! Hash::check($code, $pending['hash'])) {
            $request->session()->put('sms_login.attempts', $pending['attempts'] + 1);

            return null;
        }

        $request->session()->forget('sms_login');

        return User::find($pending['id']);
    }

    public static function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('sms_login.id');

        return $id === null ? null : User::find($id);
    }

    public static function maskedPhone(?string $phone): string
    {
        return '******'.substr((string) $phone, -4);
    }

    protected static function send(string $phone, string $code): void
    {
        $mobile = substr(preg_replace('/\D/', '', $phone), -10);
        $response = Http::timeout(10)->get(rtrim(config('services.sms.base_url'), '/').'/sendsms.jsp', [
            'user' => config('services.sms.user'),
            'password' => config('services.sms.password'),
            'senderid' => config('services.sms.sender_id'),
            'mobiles' => $mobile,
            'sms' => str_replace(':code', $code, config('services.sms.otp_message')),
            'tempid' => config('services.sms.otp_template_id'),
            'entityid' => config('services.sms.entity_id'),
            'accusage' => '1',
            'responsein' => 'json',
        ]);

        if (! $response->successful() || str_contains(strtolower($response->body()), 'error')) {
            Log::error('SMS login code could not be sent', ['status' => $response->status(), 'body' => $response->body()]);

            throw new \RuntimeException('The SMS code could not be sent. Try again in a minute.');
        }
    }
}
