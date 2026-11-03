<?php

namespace App\Livewire;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

/**
 * Fork-only replacement for the official Upgrade button. Builds of this fork all share
 * one Coolify version number, so availability compares the commit the running image was
 * built from with the newest successful build of the fork on GitHub.
 */
class CustomUpgrade extends Component
{
    public const LATEST_BUILD_CACHE_KEY = 'custom-build:latest-sha';

    public bool $fullButton = false;

    public ?string $currentSha = null;

    public ?string $latestSha = null;

    public bool $isUpdateAvailable = false;

    public bool $updateStarted = false;

    public function mount(): void
    {
        $this->currentSha = config('custom_build.sha');
    }

    public function checkUpdate(bool $fresh = false): void
    {
        if ($fresh) {
            Cache::forget(self::LATEST_BUILD_CACHE_KEY);
        }

        $this->latestSha = Cache::remember(self::LATEST_BUILD_CACHE_KEY, 300, fn (): ?string => $this->fetchLatestBuildSha());
        $this->isUpdateAvailable = filled($this->currentSha)
            && filled($this->latestSha)
            && ! str_starts_with($this->currentSha, $this->latestSha)
            && ! str_starts_with($this->latestSha, $this->currentSha);
    }

    public function update(): void
    {
        if (! isInstanceAdmin()) {
            abort(403);
        }

        $this->checkUpdate(fresh: true);
        if (! $this->isUpdateAvailable || $this->updateStarted) {
            return;
        }

        $server = Server::find(0);
        if (! $server) {
            return;
        }

        // Detached on the host: the update restarts this container, which would end a live SSH session.
        instant_remote_process([config('custom_build.update_command')], $server);
        $this->updateStarted = true;

        auditLog('ui.instance.custom_update_started', [
            'team_id' => null,
            'resource' => 'instance',
            'from_build' => $this->currentSha,
            'to_build' => $this->latestSha,
        ]);
    }

    protected function fetchLatestBuildSha(): ?string
    {
        $repository = config('custom_build.repository');
        $workflow = config('custom_build.workflow');
        $token = config('custom_build.token');

        try {
            $request = Http::acceptJson()->timeout(5);
            if (filled($token)) {
                $request = $request->withToken($token);
            }

            $response = $request->get("https://api.github.com/repos/{$repository}/actions/workflows/{$workflow}/runs", [
                'status' => 'success',
                'branch' => config('custom_build.branch'),
                'per_page' => 1,
            ]);

            if ($response->successful()) {
                return $response->json('workflow_runs.0.head_sha');
            }
        } catch (\Throwable) {
            // Fall through to public GHCR tag check
        }

        // If repo is private and no token is present, resolve the latest tag from the public GHCR registry.
        return $this->fetchLatestGhcrTag();
    }

    protected function fetchLatestGhcrTag(): ?string
    {
        try {
            $imageName = strtolower((string) config('custom_build.repository'));
            $tokenRes = Http::timeout(5)->get("https://ghcr.io/token?scope=repository:{$imageName}:pull");
            $token = $tokenRes->json('token');
            if (! $token) {
                return null;
            }

            $tagsRes = Http::withToken($token)->timeout(5)->get("https://ghcr.io/v2/{$imageName}/tags/list");
            $tags = collect($tagsRes->json('tags', []))
                ->filter(fn ($t) => $t !== 'latest' && ! str_contains($t, '-'));

            return $tags->last();
        } catch (\Throwable) {
            return null;
        }
    }

    public function render()
    {
        return view('livewire.custom-upgrade');
    }
}
