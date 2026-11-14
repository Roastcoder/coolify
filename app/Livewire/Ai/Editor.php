<?php

namespace App\Livewire\Ai;

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
        return view('livewire.ai.editor', ['url' => VscodeServer::PATH.'/'])->layout('layouts.app', ['fullBleed' => true]);
    }
}
