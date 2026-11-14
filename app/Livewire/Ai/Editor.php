<?php

namespace App\Livewire\Ai;

use App\Jobs\AiWebTaskJob;
use App\Models\AiConversation;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Services\AiAssistant;
use App\Services\ContainerFilesystemService;
use App\Services\HostFilesystemService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fork-only: the code editor, a VS Code-like workspace. Open a project's application, database or
 * service (its running container) or a server's own folders, browse the tree, edit in tabs, and ask
 * Claude to change a file (shown as a diff to accept). The page renders once; the editor in the
 * browser calls the methods below, which return data. Root access, so the page must be unlocked.
 */
class Editor extends Component
{
    /** "application:uuid", "database:uuid", "service:uuid" or "server:uuid". */
    #[Locked]
    public ?string $workspace = null;

    #[Locked]
    public string $root = '/';

    public string $unlockPassword = '';

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
    }

    /** This page gives root on every server, so check on every request. */
    public function hydrate(): void
    {
        abort_unless(isInstanceAdmin(), 403);
    }

    public function unlock(): void
    {
        $reply = app(AiAssistant::class)->handle($this->conversation(), 'unlock '.$this->unlockPassword);
        $this->unlockPassword = '';
        $this->dispatch($this->conversation()->isUnlocked() ? 'success' : 'error', $reply);
    }

    /** @return array{root: string, label: string} */
    #[Renderless]
    public function openWorkspace(string $key, string $root = ''): array
    {
        return $this->attempt(function () use ($key, $root) {
            $this->ensureUnlocked();
            $this->workspace = $key;
            $this->root = '/';
            $service = $this->files();
            $root = trim($root);
            if ($root !== '') {
                validateShellSafePath($root, 'folder');
            }
            $this->root = $root !== '' && str_starts_with($root, '/') ? rtrim($root, '/') ?: '/' : $service->defaultRoot();

            return ['root' => $this->root, 'label' => collect($this->workspaces())->flatMap(fn ($group) => $group['items'])->firstWhere('key', $key)['label'] ?? $key];
        });
    }

    /** @return array<int, array{name: string, path: string, type: string, size: int}> */
    #[Renderless]
    public function listDir(string $path): array
    {
        return $this->attempt(function () use ($path) {
            $this->ensureUnlocked();
            $path = $this->path($path);

            return collect($this->files()->list($path))
                ->map(fn ($entry) => [
                    'name' => $entry->name,
                    'path' => rtrim($path, '/').'/'.$entry->name,
                    'type' => $entry->type === 'directory' ? 'dir' : 'file',
                    'size' => (int) $entry->size,
                ])
                ->sortBy([fn ($a, $b) => strcmp($a['type'], $b['type']), fn ($a, $b) => strnatcasecmp($a['name'], $b['name'])])
                ->values()->all();
        });
    }

    #[Renderless]
    public function readFile(string $path): array
    {
        return $this->attempt(function () use ($path) {
            $this->ensureUnlocked();

            return $this->files()->read($this->path($path));
        });
    }

    #[Renderless]
    public function writeFile(string $path, string $content): array
    {
        return $this->attempt(function () use ($path, $content) {
            $this->ensureUnlocked();
            abort_if(strlen($content) > 5 * 1024 * 1024, 413, 'File too large.');
            $this->files()->write($this->path($path), $content);

            return true;
        });
    }

    #[Renderless]
    public function createEntry(string $path, bool $folder): array
    {
        return $this->attempt(function () use ($path, $folder) {
            $this->ensureUnlocked();
            $folder ? $this->files()->makeDirectory($this->path($path)) : $this->files()->createFile($this->path($path));

            return true;
        });
    }

    #[Renderless]
    public function renameEntry(string $from, string $to): array
    {
        return $this->attempt(function () use ($from, $to) {
            $this->ensureUnlocked();
            $this->files()->rename($this->path($from), $this->path($to));

            return true;
        });
    }

    #[Renderless]
    public function deleteEntry(string $path): array
    {
        return $this->attempt(function () use ($path) {
            $this->ensureUnlocked();
            $path = $this->path($path);
            abort_if($path === '/' || $path === $this->root, 422, 'This folder cannot be deleted here.');
            $this->files()->delete($path);

            return true;
        });
    }

    #[Renderless]
    public function askClaude(string $path, string $content, string $instruction): array
    {
        return $this->attempt(function () use ($path, $content, $instruction) {
            $this->ensureUnlocked();
            abort_unless(app(AiAssistant::class)->isConfigured(), 422, 'Add a Claude API key in Settings > AI assistant.');
            $instruction = trim(mb_substr($instruction, 0, 4000));
            abort_if($instruction === '', 422, 'Tell Claude what to change.');
            $conversation = $this->conversation();
            Cache::forget(AiWebTaskJob::resultKey($conversation->id));
            AiWebTaskJob::dispatch($conversation->id, 'rewrite', ['path' => $path, 'content' => $content, 'instruction' => $instruction]);

            return true;
        });
    }

    /** @return array<string, string>|null */
    #[Renderless]
    public function claudeResult(): ?array
    {
        return Cache::pull(AiWebTaskJob::resultKey($this->conversation()->id));
    }

    /**
     * Runs one editor request. The browser gets {data} or {error} with a message to show.
     *
     * @return array{data?: mixed, error?: string}
     */
    private function attempt(callable $request): array
    {
        try {
            return ['data' => $request()];
        } catch (HttpException $e) {
            return ['error' => $e->getMessage() ?: 'Not allowed.'];
        } catch (\Throwable $e) {
            report($e);

            return ['error' => Str::limit($e->getMessage(), 300)];
        }
    }

    private function ensureUnlocked(): void
    {
        abort_unless($this->conversation()->isUnlocked(), 423, 'Root access is locked. Enter the access password.');
    }

    private function path(string $path): string
    {
        abort_unless(str_starts_with($path, '/') && ! str_contains($path, "\0") && ! preg_match('#(^|/)\.\.(/|$)#', $path), 422, 'Invalid path.');
        validateShellSafePath($path, 'path');

        return $path;
    }

    private function files(): ContainerFilesystemService
    {
        abort_unless($this->workspace, 422, 'Open a workspace first.');
        [$type, $uuid] = explode(':', $this->workspace, 2) + [1 => ''];
        $teamIds = auth()->user()->teams()->pluck('teams.id')->all();

        if ($type === 'server') {
            $server = Server::query()->where('uuid', $uuid)->whereIn('team_id', $teamIds)->first();
            abort_unless($server && Gate::allows('update', $server), 404);

            return new HostFilesystemService($server, $this->root);
        }

        $resource = queryResourcesByUuid($uuid);
        abort_unless($resource && method_exists($resource, 'team') && in_array($resource->team()?->id, $teamIds, true) && Gate::allows('update', $resource), 404);
        $server = $resource instanceof Service ? $resource->server : $resource->destination->server;
        $container = $this->container($resource, $server);
        abort_unless($container, 409, 'This resource is not running, so its files cannot be opened.');

        return new ContainerFilesystemService($server, $container);
    }

    private function container(mixed $resource, Server $server): ?string
    {
        $names = match (true) {
            $resource instanceof Application => getCurrentApplicationContainerStatus($server, $resource)
                ->filter(fn ($c) => data_get($c, 'State') === 'running')->map(fn ($c) => data_get($c, 'Names'))->values()->all(),
            $resource instanceof Service => $resource->applications()->get()->filter->isRunning()->map(fn ($app) => $app->name.'-'.$resource->uuid)->values()->all(),
            default => $resource->isRunning() ? [$resource->uuid] : [],
        };

        return collect($names)->first(fn ($name) => is_string($name) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name));
    }

    private function conversation(): AiConversation
    {
        $user = auth()->user();

        return AiConversation::query()->firstOrCreate(['channel' => 'web', 'channel_id' => (string) $user->id], ['user_id' => $user->id, 'messages' => []]);
    }

    /** @return array<int, array{label: string, items: array<int, array{key: string, label: string, kind: string}>}> */
    private function workspaces(): array
    {
        $teamIds = auth()->user()->teams()->pluck('teams.id')->all();
        $groups = Project::query()->whereIn('team_id', $teamIds)->with('environments')->orderBy('name')->get()
            ->map(function (Project $project) {
                $items = [];
                foreach ($project->environments as $environment) {
                    $suffix = $project->environments->count() > 1 ? ' · '.$environment->name : '';
                    foreach ($environment->applications as $app) {
                        $items[] = ['key' => 'application:'.$app->uuid, 'label' => $app->name.$suffix, 'kind' => 'App'];
                    }
                    foreach ($environment->services as $service) {
                        $items[] = ['key' => 'service:'.$service->uuid, 'label' => $service->name.$suffix, 'kind' => 'Service'];
                    }
                    foreach ($environment->databases() as $database) {
                        $items[] = ['key' => 'database:'.$database->uuid, 'label' => $database->name.$suffix, 'kind' => 'Database'];
                    }
                }

                return ['label' => $project->name, 'items' => $items];
            })
            ->filter(fn ($group) => $group['items'] !== [])->values();

        $servers = Server::query()->whereIn('team_id', $teamIds)->orderBy('name')->get()
            ->map(fn (Server $server) => ['key' => 'server:'.$server->uuid, 'label' => $server->name.' ('.$server->ip.')', 'kind' => 'Server'])->all();

        return [...$groups->all(), ['label' => 'Servers', 'items' => $servers]];
    }

    public function render()
    {
        return view('livewire.ai.editor', [
            'workspaces' => $this->workspaces(),
            'unlocked' => $this->conversation()->isUnlocked(),
            'configured' => app(AiAssistant::class)->isConfigured(),
        ])->layout('layouts.app', ['fullBleed' => true]);
    }
}
