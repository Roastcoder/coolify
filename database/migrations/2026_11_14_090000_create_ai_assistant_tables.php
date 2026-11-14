<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fork-only: the AI assistant (App\Services\AiAssistant). One settings row.
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->text('api_key')->nullable();
            $table->string('model')->default('claude-opus-5-5');
            $table->string('access_password')->nullable();
            // The built-in WhatsApp gateway (App\Services\WhatsappGateway).
            $table->text('whatsapp_secret')->nullable();
            $table->string('whatsapp_root_numbers')->nullable();
            $table->string('whatsapp_notify_numbers')->nullable();
            $table->boolean('whatsapp_notifications_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('channel_id');
            $table->json('messages')->nullable();
            $table->json('pending_action')->nullable();
            $table->timestamp('unlocked_until')->nullable();
            // WhatsApp chats from numbers that are not root numbers only get read-only dashboard data.
            $table->boolean('read_only')->default(false);
            $table->timestamps();
            $table->unique(['channel', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_settings');
    }
};
