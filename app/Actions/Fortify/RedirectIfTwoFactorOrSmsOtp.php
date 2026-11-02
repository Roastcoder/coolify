<?php

namespace App\Actions\Fortify;

use App\Services\SmsLoginOtp;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

/**
 * Fork-only: after a correct password, users with authenticator-app 2FA get Fortify's usual
 * challenge; users with a mobile number get an SMS code; everyone else signs in directly.
 */
class RedirectIfTwoFactorOrSmsOtp extends RedirectIfTwoFactorAuthenticatable
{
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user->two_factor_secret && $user->two_factor_confirmed_at !== null) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        if (! SmsLoginOtp::appliesTo($user)) {
            return $next($request);
        }

        try {
            SmsLoginOtp::start($request, $user, $request->boolean('remember'));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([Fortify::username() => [$e->getMessage()]]);
        }

        return $request->wantsJson()
            ? response()->json(['sms_otp' => true])
            : redirect()->route('login.sms-otp');
    }
}
