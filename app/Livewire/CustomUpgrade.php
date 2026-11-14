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

    public const CHECKED_AT_CACHE_KEY = 'custom-build:checked-at';

    /** Set while an update runs, so every open page shows its progress. */
    public const RUNNING_CACHE_KEY = 'custom-build:update-running';

    /** Written by update-custom.sh at each stage (custom/update.sh). */
    public const STATUS_FILE = '/data/coolify/source/.custom-update-status.json';

    public bool $fullButton = false;

    public ?string $currentSha = null;

    public ?string $latestSha = null;

    public bool $isUpdateAvailable = false;

    public bool $updateStarted = false;

    public function mount(): void
    {
        $this->currentSha = config('custom_build.sha');
        $this->updateStarted = Cache::has(self::RUNNING_CACHE_KEY);
    }

    /**
     * Where a running update is: {stage, percent, message}. Stage is idle, starting, downloading, restarting,
     * done or failed. Only instance admins see it; it reads the status file only while an update runs.
     *
     * @return array{stage: string, percent: int, message: string}
     */
    public static function progress(): array
    {
        if (! isInstanceAdmin() || ! Cache::has(self::RUNNING_CACHE_KEY)) {
            return ['stage' => 'idle', 'percent' => 0, 'message' => ''];
        }
        $server = Server::find(0);
        $status = $server ? json_decode((string) instant_remote_process(['cat '.self::STATUS_FILE.' 2>/dev/null || true'], $server, false), true) : null;
        $stage = is_array($status) && in_array($status['stage'] ?? null, ['starting', 'downloading', 'restarting', 'done', 'failed'], true) ? $status['stage'] : 'starting';
        if (in_array($stage, ['done', 'failed'], true)) {
            Cache::forget(self::RUNNING_CACHE_KEY);
            Cache::forget(self::LATEST_BUILD_CACHE_KEY);
        }

        return [
            'stage' => $stage,
            'percent' => max(0, min(100, (int) ($status['percent'] ?? 2))),
            'message' => is_string($status['message'] ?? null) ? mb_substr($status['message'], 0, 120) : 'Starting the update',
        ];
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

        $this->latestSha = Cache::remember(self::LATEST_BUILD_CACHE_KEY, 300, function (): ?string {
            Cache::put(self::CHECKED_AT_CACHE_KEY, now()->toIso8601String(), now()->addWeek());

            return $this->fetchLatestBuildSha();
        });
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
        if (! $this->updateStarted) {
            $this->updateStarted = $this->installLatest('ui.instance.custom_update_started');
        }
    }

    /**
     * Installs the newest build when it differs from the running one. Also used by automatic updates.
     * Returns whether an update was started.
     */
    public function installLatest(string $auditEvent = 'instance.custom_update_started'): bool
    {
        $this->checkUpdate(fresh: true);
        $server = Server::find(0);
        if (! $this->isUpdateAvailable || ! $server) {
            return false;
        }

        // Detached on the host: the update restarts this container, which would end a live SSH session.
        // The old status file goes first, so a finished earlier update does not show as this one.
        Cache::put(self::RUNNING_CACHE_KEY, true, now()->addMinutes(20));
        instant_remote_process(['rm -f '.self::STATUS_FILE, config('custom_build.update_command')], $server);

        auditLog($auditEvent, [
            'team_id' => null,
            'resource' => 'instance',
            'from_build' => $this->currentSha,
            'to_build' => $this->latestSha,
        ]);

        return true;
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
