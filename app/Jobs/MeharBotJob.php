<?php

namespace App\Jobs;

use App\Services\MeharBot;
use App\Services\WhatsappGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fork-only: answers one WhatsApp message with the Mehar Advisory bot. Encrypted because the text can be a password or OTP.
 */
class MeharBotJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A retry could upload a document twice, so it runs once. */
    public $tries = 1;

    public $timeout = 300;

    /** @param  array{id: string, mimetype: string, filename: ?string, size: int}|null  $media */
    public function __construct(public string $number, public string $text, public ?array $media = null)
    {
        $this->onQueue('high');
    }

    public function handle(WhatsappGateway $gateway): void
    {
        try {
            $replies = (new MeharBot($this->number))->handle($this->text, $this->media);
        } catch (\Throwable $e) {
            report($e);
            $replies = ['Something went wrong. Try again, or send *menu*.'];
        }

        foreach ($replies as $reply) {
            is_array($reply)
                ? $gateway->sendFile($this->number, $reply['text'], $reply['file'], $reply['filename'], $reply['mimetype'])
                : $gateway->send($this->number, $reply);
        }
    }
}
