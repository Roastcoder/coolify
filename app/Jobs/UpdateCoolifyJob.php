<?php

namespace App\Jobs;

use App\Actions\Server\UpdateCoolify;
use App\Livewire\CustomUpgrade;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateCoolifyJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            // Fork: builds of this fork update to the newest fork build, never with the upstream installer.
            if (filled(config('custom_build.repository'))) {
                $upgrade = app(CustomUpgrade::class);
                $upgrade->mount();
                Log::info($upgrade->installLatest('instance.custom_auto_update_started') ? 'Automatic update to the newest build started.' : 'Already on the newest build. Skipping update.');

                return;
            }

            CheckForUpdatesJob::dispatchSync();
            $settings = instanceSettings();
            if (! $settings->new_version_available) {
                Log::info('No new version available. Skipping update.');

                return;
            }

            $server = Server::findOrFail(0);
            if (! $server) {
                Log::error('Server not found. Cannot proceed with update.');

                return;
            }

            Log::info('Starting Coolify update process...');
            UpdateCoolify::run(false); // false means it's not a manual update

            Log::info('Coolify upgrade script started. Result: /data/coolify/source/.upgrade-status, log: /data/coolify/source/upgrade-*.log.');
        } catch (\Throwable $e) {
            Log::error('UpdateCoolifyJob failed: '.$e->getMessage());
            // Consider implementing a notification to administrators
        }
    }
}
