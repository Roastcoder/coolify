<div x-data="{
        running: @js($updateStarted),
        percent: 2,
        label: 'Starting the update',
        failed: false,
        seen: false,
        timer: null,
        start() {
            if (this.timer) return;
            this.running = true;
            this.tick();
            this.timer = setInterval(() => this.tick(), 2000);
        },
        stop() { clearInterval(this.timer); this.timer = null; },
        async tick() {
            try {
                const res = await fetch(@js(route('settings.updates.progress')), { cache: 'no-store', headers: { Accept: 'application/json' } });
                if (! res.ok || ! (res.headers.get('content-type') || '').includes('json')) throw new Error('down');
                const p = await res.json();
                if (p.stage === 'done' || (p.stage === 'idle' && this.seen)) {
                    this.percent = 100; this.label = 'Updated, reloading';
                    this.stop(); setTimeout(() => window.location.reload(), 600);
                    return;
                }
                if (p.stage === 'idle') { this.running = false; this.stop(); return; }
                if (p.stage === 'failed') { this.failed = true; this.label = p.message; this.stop(); return; }
                this.seen = true;
                this.percent = Math.max(this.percent, p.percent);
                this.label = p.message;
            } catch (e) {
                // The panel is restarting on the new version: keep the bar moving until it answers again.
                this.seen = true;
                this.label = 'Restarting the panel';
                this.percent = Math.min(97, Math.max(this.percent, 75) + 1); // the restart takes about a minute
            }
        }
    }"
    x-init="$wire.checkUpdate(); if (running) start()"
    x-effect="if ($wire.updateStarted) start()"
    class="inline-flex items-center gap-2"
    @if ($isUpdateAvailable) title="Update to the newest build ({{ substr($latestSha ?? '', 0, 7) }})" @endif>

    @if ($fullButton)
        {{-- Settings > Updates: the button, then a progress bar while the update runs. --}}
        <template x-if="running">
            <div class="flex w-64 max-w-full flex-col gap-1.5" role="status" aria-live="polite">
                <div class="flex items-center justify-between gap-3 text-xs">
                    <span class="truncate font-medium" :class="failed ? 'text-error' : 'text-neutral-700 dark:text-neutral-200'" x-text="label"></span>
                    <span class="shrink-0 font-mono tabular-nums text-neutral-500 dark:text-neutral-400" x-show="! failed" x-text="percent + '%'"></span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-neutral-200 dark:bg-white/10">
                    <div class="h-full rounded-full transition-[width] duration-700 ease-out"
                        :class="failed ? 'bg-error' : 'bg-success'" :style="`width: ${failed ? 100 : percent}%`"></div>
                </div>
            </div>
        </template>
        @if ($isUpdateAvailable && ! $updateStarted)
            <div x-show="! running">
                <x-forms.button type="button" wire:click="update" isHighlighted>
                    <span wire:loading.remove wire:target="update" class="inline-flex items-center gap-1.5">
                        <x-reicon name="download" class="size-3.5" />
                        Update now
                    </span>
                    <span wire:loading wire:target="update">Starting…</span>
                </x-forms.button>
            </div>
        @endif
    @else
        {{-- Header badge: "Updating 45%" while it runs, else the update offer. --}}
        <template x-if="running">
            <span class="inline-flex h-[18px] items-center gap-1.5 rounded-full px-2 text-[9.5px] font-semibold leading-none ring-1 ring-inset"
                :class="failed ? 'bg-error/10 text-error ring-error/25' : 'bg-warning/15 text-warning ring-warning/25'"
                :title="label" role="status">
                <span class="relative h-1 w-8 overflow-hidden rounded-full bg-current/20" x-show="! failed">
                    <span class="absolute inset-y-0 left-0 rounded-full bg-current transition-[width] duration-700 ease-out" :style="`width: ${percent}%`"></span>
                </span>
                <span x-text="failed ? 'Update failed' : 'Updating ' + percent + '%'"></span>
            </span>
        </template>
        @if ($isUpdateAvailable && ! $updateStarted)
            <button type="button" wire:click="update" x-show="! running"
                aria-label="New version available"
                title="Click to install update ({{ substr($latestSha ?? '', 0, 7) }})"
                class="inline-flex h-[18px] cursor-pointer items-center rounded-full bg-coollabs/10 px-1.5 text-[9.5px] font-semibold leading-none text-coollabs ring-1 ring-inset ring-coollabs/25 transition-colors hover:bg-coollabs/15 dark:bg-brand/15 dark:text-brand-light dark:ring-brand/25 dark:hover:bg-brand/20">
                <span wire:loading.remove wire:target="update">New version available</span>
                <span wire:loading wire:target="update">Starting…</span>
            </button>
        @endif
    @endif
</div>
