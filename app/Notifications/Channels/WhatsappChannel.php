<?php

namespace App\Notifications\Channels;

use App\Jobs\SendWhatsappMessageJob;
use App\Models\AiSettings;
use App\Models\Team;

/**
 * Fork-only: sends notifications to the numbers in Settings > AI assistant through the built-in WhatsApp gateway.
 * The text is the Telegram text, which every notification has and which reads fine as plain text.
 */
class WhatsappChannel
{
    /** Only teams with an instance admin, so one customer's events never reach another's phone. */
    public static function isEnabledFor(mixed $notifiable): bool
    {
        try {
            $settings = AiSettings::current();
        } catch (\Throwable) {
            return false;
        }

        return $notifiable instanceof Team
            && $settings->whatsapp_notifications_enabled
            && $settings->notifyNumbers() !== []
            && $notifiable->members()->get()->contains(fn ($user) => $user->isInstanceAdmin());
    }

    public function send($notifiable, $notification): void
    {
        if (! method_exists($notification, 'toTelegram')) {
            return;
        }
        $message = trim((string) data_get($notification->toTelegram($notifiable), 'message'));
        if ($message === '') {
            return;
        }

        foreach (AiSettings::current()->notifyNumbers() as $number) {
            SendWhatsappMessageJob::dispatch($number, $message);
        }
    }
}
