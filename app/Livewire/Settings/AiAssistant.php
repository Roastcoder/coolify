<?php

namespace App\Livewire\Settings;

use App\Models\AiSettings;
use App\Services\WhatsappGateway;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Fork-only: Settings > AI assistant. Claude API key and model, the access password that unlocks
 * actions, and the built-in WhatsApp gateway (link with a QR code, notifications).
 */
class AiAssistant extends Component
{
    /** Left empty: the saved key is never sent to the browser. A new value replaces it. */
    public string $apiKey = '';

    public string $model = 'claude-opus-5-5';

    public string $accessPassword = '';

    public string $rootNumbers = '';

    public string $notifyNumbers = '';

    public bool $notificationsEnabled = false;

    #[Locked]
    public bool $hasApiKey = false;

    #[Locked]
    public bool $hasAccessPassword = false;

    /** @var array{state: string, qr: ?string, me: ?string} */
    #[Locked]
    public array $whatsapp = ['state' => 'offline', 'qr' => null, 'me' => null];

    #[Locked]
    public string $buildLog = '';

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        $settings = AiSettings::current();
        $this->model = $settings->model;
        $this->rootNumbers = (string) $settings->whatsapp_root_numbers;
        $this->notifyNumbers = (string) $settings->whatsapp_notify_numbers;
        $this->notificationsEnabled = $settings->whatsapp_notifications_enabled;
        $this->loadState();
    }

    /** The key, the password and WhatsApp give root on every server, so check on every request. */
    public function hydrate(): void
    {
        abort_unless(isInstanceAdmin(), 403);
    }

    public function save(): void
    {
        $this->validate([
            'apiKey' => ['nullable', 'string', 'max:300'],
            'model' => ['required', 'in:'.implode(',', array_keys(AiSettings::MODELS))],
            'accessPassword' => ['nullable', 'string', 'min:8', 'max:200'],
            'rootNumbers' => ['nullable', 'string', 'max:500'],
        ]);

        $changes = ['model' => $this->model, 'whatsapp_root_numbers' => implode(', ', AiSettings::numbers($this->rootNumbers))];
        $this->rootNumbers = $changes['whatsapp_root_numbers'];
        if (filled($this->apiKey)) {
            $changes['api_key'] = trim($this->apiKey);
        }
        if (filled($this->accessPassword)) {
            $changes['access_password'] = $this->accessPassword;
        }
        AiSettings::current()->update($changes);

        $this->reset('apiKey', 'accessPassword');
        $this->loadState();
        $this->dispatch('success', 'AI assistant saved.');
    }

    public function saveNotifications(): void
    {
        $this->validate(['notifyNumbers' => ['nullable', 'string', 'max:500']]);
        $settings = AiSettings::current();
        $settings->update(['whatsapp_notify_numbers' => $this->notifyNumbers, 'whatsapp_notifications_enabled' => $this->notificationsEnabled]);
        $this->notifyNumbers = implode(', ', $settings->notifyNumbers());
        $this->dispatch('success', 'WhatsApp notifications saved.');
    }

    public function startWhatsapp(): void
    {
        try {
            app(WhatsappGateway::class)->start();
            $this->whatsapp['state'] = 'starting';
            $this->dispatch('success', 'Building the WhatsApp gateway. The first start takes a few minutes.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function stopWhatsapp(): void
    {
        try {
            app(WhatsappGateway::class)->stop();
            $this->loadState();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function logoutWhatsapp(): void
    {
        try {
            app(WhatsappGateway::class)->logout();
            $this->dispatch('success', 'WhatsApp unlinked. Scan the new QR code to link again.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function sendTest(): void
    {
        try {
            $settings = AiSettings::current();
            $numbers = $settings->notifyNumbers() ?: array_filter([preg_replace('/\D/', '', (string) auth()->user()->phone)]);
            if ($numbers === []) {
                $this->dispatch('error', 'Add a number to send notifications to first.');

                return;
            }
            foreach ($numbers as $number) {
                app(WhatsappGateway::class)->send($number, 'Test message from StandaloneCoders Cloud. WhatsApp is working.');
            }
            $this->dispatch('success', 'Test message sent.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function loadState(): void
    {
        $settings = AiSettings::current();
        $this->hasApiKey = filled($settings->api_key);
        $this->hasAccessPassword = filled($settings->getAttributes()['access_password'] ?? null);
        $gateway = app(WhatsappGateway::class);
        $this->whatsapp = $gateway->status();
        try {
            $this->buildLog = $this->whatsapp['state'] === 'offline' ? $gateway->buildLog() : '';
        } catch (\Throwable) {
            $this->buildLog = '';
        }
    }

    public function render()
    {
        return view('livewire.settings.ai-assistant', [
            'models' => collect(AiSettings::MODELS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'adminPhone' => auth()->user()->phone,
        ]);
    }
}
