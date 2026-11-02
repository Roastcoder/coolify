<?php

namespace App\Http\Controllers;

use App\Services\SmsLoginOtp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Fork-only: the SMS code page that follows a correct password (see RedirectIfTwoFactorOrSmsOtp).
 */
class SmsOtpLoginController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = SmsLoginOtp::pendingUser($request);
        if (! $user) {
            return redirect()->route('login');
        }

        return view('auth.sms-otp', ['phone' => SmsLoginOtp::maskedPhone($user->phone)]);
    }

    public function check(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        $remember = (bool) $request->session()->get('sms_login.remember');
        $user = SmsLoginOtp::verify($request, (string) $request->input('code'));

        if (! $user) {
            if (! SmsLoginOtp::pendingUser($request)) {
                return redirect()->route('login')->withErrors(['email' => 'The code expired or was entered wrong too many times. Sign in again.']);
            }

            return back()->withErrors(['code' => 'That code is not right.']);
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = SmsLoginOtp::pendingUser($request);
        if (! $user) {
            return redirect()->route('login');
        }

        try {
            SmsLoginOtp::start($request, $user, (bool) $request->session()->get('sms_login.remember'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('status', 'A new code is on its way.');
    }
}
