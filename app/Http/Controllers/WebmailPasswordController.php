<?php

namespace App\Http\Controllers;

use App\Services\MailServer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Fork-only: Settings > Password in the webmail posts here. The secret in the URL is known only to the
 * webmail container, and the current password must still sign in to the mailbox before it changes.
 */
class WebmailPasswordController extends Controller
{
    public function __invoke(Request $request, string $token, MailServer $mail): Response
    {
        abort_unless(hash_equals($mail->webmailPasswordToken(), $token), 404);

        $email = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        if (strlen($password) < 10 || strlen($password) > 128
            || ! in_array($email, $mail->mailboxes(), true)
            || ! $mail->checkMailboxPassword($email, (string) $request->input('current_password'))) {
            return response('Password not changed', 422);
        }

        $mail->setPassword($email, $password);

        return response('OK');
    }
}
