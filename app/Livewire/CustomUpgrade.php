<?php

namespace App\Livewire;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\On;
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

    #[On('check-custom-update')]
    #[On('updateAvailable')]
    public function refreshUpdateStatus(): void
    {
        $this->checkUpdate(fresh: true);
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

        // A private repository without a token: read the commit that :latest was built from, in the public registry.
        return $this->fetchLatestImageRevision();
    }

    /**
     * The org.opencontainers.image.revision label of ghcr.io/<repository>:latest (set by custom-build.yml).
     * Tag names cannot tell which build is newest, so the label is the only reliable answer without GitHub access.
     */
    protected function fetchLatestImageRevision(): ?string
    {
        try {
            $image = strtolower((string) config('custom_build.repository'));
            $token = Http::timeout(5)->get('https://ghcr.io/token', ['scope' => "repository:{$image}:pull"])->json('token');
            if (! $token) {
                return null;
            }
            $registry = Http::withToken($token)->timeout(10)->accept(implode(', ', [
                'application/vnd.oci.image.index.v1+json',
                'application/vnd.docker.distribution.manifest.list.v2+json',
                'application/vnd.oci.image.manifest.v1+json',
                'application/vnd.docker.distribution.manifest.v2+json',
            ]));

            $manifest = $registry->get("https://ghcr.io/v2/{$image}/manifests/latest")->json();
            // A multi-arch index lists one manifest per platform (and attestations with os "unknown").
            $platform = collect($manifest['manifests'] ?? [])->first(fn (array $m) => ($m['platform']['os'] ?? null) === 'linux');
            if ($platform) {
                $manifest = $registry->get("https://ghcr.io/v2/{$image}/manifests/{$platform['digest']}")->json();
            }
            $config = $manifest['config']['digest'] ?? null;
            if (! $config) {
                return null;
            }
            $revision = $registry->get("https://ghcr.io/v2/{$image}/blobs/{$config}")->json('config.Labels')['org.opencontainers.image.revision'] ?? null;

            return is_string($revision) && preg_match('/^[0-9a-f]{7,40}$/', $revision) ? $revision : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function render()
    {
        return view('livewire.custom-upgrade');
    }
}
