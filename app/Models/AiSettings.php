<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fork-only: settings of the AI assistant. A single row, read with AiSettings::current().
 */
class AiSettings extends Model
{
    /** Runs on this server in Ollama (container coolify-ollama), no API key needed. */
    public const LOCAL_PREFIX = 'local:';

    public const MODELS = [
        'local:qwen3:1.7b' => 'Local Qwen3 1.7B (free, runs on your server, basic)',
        'claude-opus-5-5' => 'Claude Opus 5.5 (best)',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (faster, cheaper)',
        'claude-haiku-5-5' => 'Claude Haiku 5.5 (cheapest)',
    ];

    protected $fillable = [
        'api_key', 'model', 'access_password',
        'whatsapp_secret', 'whatsapp_root_numbers', 'whatsapp_notify_numbers', 'whatsapp_notifications_enabled',
    ];

    protected $hidden = ['api_key', 'access_password', 'whatsapp_secret'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'access_password' => 'hashed',
            'whatsapp_secret' => 'encrypted',
            'whatsapp_notifications_enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1], ['model' => 'claude-opus-5-5', 'whatsapp_notifications_enabled' => false]);
    }

    public function isLocal(): bool
    {
        return str_starts_with((string) $this->model, self::LOCAL_PREFIX);
    }

    /** @return array<int, string> Numbers that get notifications, digits only. */
    public function notifyNumbers(): array
    {
        return self::numbers($this->whatsapp_notify_numbers);
    }

    /** WhatsApp numbers with full access (root actions still need the access password). */
    public function isRootNumber(string $number): bool
    {
        $last10 = substr(preg_replace('/\D/', '', $number), -10);

        return strlen($last10) === 10 && collect(self::numbers($this->whatsapp_root_numbers))->contains(fn (string $root) => substr($root, -10) === $last10);
    }

    /** @return array<int, string> */
    public static function numbers(?string $list): array
    {
        return collect(preg_split('/[,;\n]+/', (string) $list))
            ->map(fn (string $number) => preg_replace('/\D/', '', $number))->filter()->unique()->values()->all();
    }
}
