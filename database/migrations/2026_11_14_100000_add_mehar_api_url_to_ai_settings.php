<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Fork-only: the Mehar Advisory backend the WhatsApp bot talks to. Empty keeps the bot off. */
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('mehar_api_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('mehar_api_url');
        });
    }
};
