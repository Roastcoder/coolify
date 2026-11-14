<?php

namespace App\Jobs;

use App\Services\WhatsappGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Fork-only: one notification message through the built-in WhatsApp gateway. */
class SendWhatsappMessageJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 3;

    public $backoff = 30;

    public function __construct(public string $number, public string $message)
    {
        $this->onQueue('high');
    }

    public function handle(WhatsappGateway $gateway): void
    {
        $gateway->send($this->number, $this->message);
    }
}
