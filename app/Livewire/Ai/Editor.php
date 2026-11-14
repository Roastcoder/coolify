<?php

namespace App\Livewire\Ai;

use App\Models\Application;
use App\Services\VscodeServer;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Fork-only: the Code page. VS Code (App\Services\VscodeServer) full screen, for instance admins.
 */
class Editor extends Component
{
    #[Locked]
    public bool $running = false;

    /** The folder VS Code shows. Set only by openProject(). */
    #[Locked]
    public string $folder = VscodeServer::ALL_FILES;

    /** The app picked in the project list, or '' for all files. */
    public string $project = '';

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        $this->refreshState();
    }

    /** VS Code gives root on the host, so check on every request. */
    public function hydrate(): void
    {
        abort_unless(isInstanceAdmin(), 403);
    }

    public function refreshState(): void
    {
        try {
            $this->running = app(VscodeServer::class)->isRunning();
        } catch (\Throwable) {
            $this->running = false;
        }
    }

    public function start(): void
    {
        try {
            app(VscodeServer::class)->start();
            $this->running = true;
            $this->dispatch('success', 'VS Code is starting. The first start downloads it and takes a minute.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function openProject(): void
    {
        try {
            if ($this->project === '') {
                $this->folder = VscodeServer::ALL_FILES;

                return;
            }
            $application = $this->applications()->firstWhere('uuid', $this->project);
            if (! $application) {
                throw new \RuntimeException('App not found.');
            }
            $this->folder = app(VscodeServer::class)->openProject($application);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /** Apps built from a git repository. Only instance admins reach this page, and they manage every app. */
    private function applications()
    {
        return Application::query()->whereNotNull('git_repository')->where('build_pack', '!=', 'dockerimage')->orderBy('name')->get();
    }

    public function stop(): void
    {
        try {
            app(VscodeServer::class)->stop();
            $this->running = false;
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.ai.editor', [
            'url' => VscodeServer::PATH.'/?folder='.rawurlencode($this->folder),
            'projects' => [['value' => '', 'label' => 'All files'], ...$this->applications()->map(fn (Application $application) => ['value' => $application->uuid, 'label' => $application->name])->all()],
        ])->layout('layouts.app', ['fullBleed' => true]);
    }
}
