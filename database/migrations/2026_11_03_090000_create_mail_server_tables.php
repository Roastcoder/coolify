<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_domains', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('mail_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('address')->unique();
            $table->string('destination');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_aliases');
        Schema::dropIfExists('mail_domains');
    }
};
