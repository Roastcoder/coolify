<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fork-only: one chat with the AI assistant, in the web app (channel_id = user id) or on WhatsApp (channel_id = phone).
 * `messages` holds the Claude API messages exactly as sent, so thinking blocks go back unchanged.
 */
class AiConversation extends Model
{
    protected $fillable = ['user_id', 'channel', 'channel_id', 'messages', 'pending_action', 'unlocked_until', 'read_only'];

    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'pending_action' => 'array',
            'unlocked_until' => 'datetime',
            'read_only' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUnlocked(): bool
    {
        return $this->unlocked_until?->isFuture() ?? false;
    }
}
