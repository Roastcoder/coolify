<?php

namespace App\Services;

use App\Models\AiSettings;
use App\Models\Server;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fork-only: the built-in WhatsApp gateway, a whatsapp-web.js container (resources/whatsapp) next to Coolify.
 * It is built and started on the Coolify host, joins the `coolify` network, and talks to Coolify over it.
 */
class WhatsappGateway
{
    public const CONTAINER = 'coolify-whatsapp';

    public const IMAGE = 'standalonecoders-whatsapp:latest';

    private const DIR = '/data/coolify/whatsapp';

    /** WhatsApp allows 4096 characters per message; split longer replies. */
    private const MESSAGE_LIMIT = 3500;

    public function __construct(private ?AiSettings $settings = null)
    {
        $this->settings ??= AiSettings::current();
    }

    public function secret(): string
    {
        if (blank($this->settings->whatsapp_secret)) {
            $this->settings->update(['whatsapp_secret' => Str::random(48)]);
        }

        return $this->settings->whatsapp_secret;
    }

    /**
     * Builds the image and (re)starts the container. The build takes a few minutes the first time,
     * so it runs in the background on the host; status() shows the QR code once it is up.
     */
    public function start(): void
    {
        $dir = self::DIR;
        $commands = ["mkdir -p {$dir}/app {$dir}/session"];
        foreach (['Dockerfile', 'package.json', 'index.js'] as $file) {
            $commands[] = 'printf %s '.escapeshellarg(base64_encode(file_get_contents(resource_path("whatsapp/{$file}"))))." | base64 -d > {$dir}/app/{$file}";
        }
        $run = 'docker rm -f '.self::CONTAINER.' >/dev/null 2>&1; docker build -t '.self::IMAGE." {$dir}/app && "
            .'docker run -d --name '.self::CONTAINER.' --restart always --network coolify --shm-size 512m '
            .'-e SECRET='.escapeshellarg($this->secret()).' -e WEBHOOK_URL='.escapeshellarg('http://coolify:8080/webhooks/whatsapp')
            ." -v {$dir}/session:/data ".self::IMAGE;
        $commands[] = 'nohup sh -c '.escapeshellarg($run)." > {$dir}/build.log 2>&1 &";

        instant_remote_process($commands, $this->host());
    }

    public function stop(): void
    {
        instant_remote_process(['docker rm -f '.self::CONTAINER.' >/dev/null 2>&1 || true'], $this->host());
    }

    /** Unlinks the WhatsApp account; the container restarts and shows a new QR code. */
    public function logout(): void
    {
        $this->request()->post($this->url('/logout'));
    }

    /** @return array{state: string, qr: ?string, me: ?string} */
    public function status(): array
    {
        try {
            $status = $this->request()->timeout(5)->get($this->url('/status'))->throw()->json();

            return ['state' => (string) ($status['state'] ?? 'starting'), 'qr' => $status['qr'] ?? null, 'me' => $status['me'] ?? null];
        } catch (\Throwable) {
            return ['state' => 'offline', 'qr' => null, 'me' => null];
        }
    }

    public function isLinked(): bool
    {
        return $this->status()['state'] === 'ready';
    }

    /** Tail of the last build and start, for the settings page while the gateway is not up. */
    public function buildLog(): string
    {
        return (string) instant_remote_process(['tail -n 15 '.self::DIR.'/build.log 2>/dev/null || true'], $this->host(), false);
    }

    public function send(string $number, string $text): void
    {
        $number = preg_replace('/\D/', '', $number);
        foreach (mb_str_split($text, self::MESSAGE_LIMIT) as $part) {
            $this->request()->timeout(30)->post($this->url('/send'), ['to' => $number, 'text' => $part])->throw();
        }
    }

    /** Sends a file (a PDF, a photo) as a document, with a short caption. */
    public function sendFile(string $number, string $caption, string $contents, string $filename, string $mimetype = 'application/pdf'): void
    {
        $this->request()->timeout(120)->post($this->url('/send'), [
            'to' => preg_replace('/\D/', '', $number),
            'text' => mb_substr($caption, 0, 1000),
            'file' => ['mimetype' => $mimetype, 'data' => base64_encode($contents), 'filename' => $filename],
        ])->throw();
    }

    /**
     * A file someone sent in the last 15 minutes, by the id the gateway gave it.
     *
     * @return array{mimetype: string, contents: string, filename: ?string}|null
     */
    public function media(string $id): ?array
    {
        $response = $this->request()->timeout(60)->get($this->url('/media/'.rawurlencode($id)));
        if (! $response->successful()) {
            return null;
        }

        return ['mimetype' => (string) $response->json('mimetype'), 'contents' => (string) base64_decode((string) $response->json('data')), 'filename' => $response->json('filename')];
    }

    public function isValidSecret(?string $given): bool
    {
        $secret = $this->settings->whatsapp_secret;

        return filled($secret) && is_string($given) && hash_equals($secret, $given);
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['X-Whatsapp-Secret' => $this->secret()])->connectTimeout(5)->acceptJson();
    }

    private function url(string $path): string
    {
        return 'http://'.self::CONTAINER.':3000'.$path;
    }

    protected function host(): Server
    {
        return Server::findOrFail(0);
    }
}
