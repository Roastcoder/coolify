<div x-data="{
        pollInterval: null,
        wentDown: false,
        startPolling() {
            if (this.pollInterval) return;
            this.pollInterval = setInterval(async () => {
                try {
                    const res = await fetch('/api/health', { cache: 'no-store' });
                    if (res.ok) {
                        if (this.wentDown) {
                            clearInterval(this.pollInterval);
                            window.location.reload();
                        }
                    } else {
                        this.wentDown = true;
                    }
                } catch (e) {
                    this.wentDown = true;
                }
            }, 3000);
        }
    }"
    x-init="$wire.checkUpdate(); if (@js($updateStarted)) { startPolling(); }"
    x-effect="if ($wire.updateStarted) { startPolling(); }"
    class="inline-flex items-center gap-2"
    @if ($isUpdateAvailable) title="Update to latest build ({{ substr($latestSha ?? '', 0, 7) }})" @endif>

    @if ($isUpdateAvailable || $updateStarted)
        @if ($fullButton)
            <div class="flex items-center gap-2">
                @if ($updateStarted)
                    <x-forms.button type="button" disabled class="cursor-wait opacity-90">
                        <svg class="size-3.5 animate-spin text-white" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>Updating… auto-reloading</span>
                    </x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="update" isHighlighted>
                        <span wire:loading.remove wire:target="update">
                            New version available! Update now
                        </span>
                        <span wire:loading wire:target="update" class="inline-flex items-center gap-1.5">
                            <svg class="size-3.5 animate-spin text-white" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span>Starting update…</span>
                        </span>
                    </x-forms.button>
                    @if (filled($currentSha) && filled($latestSha))
                        <a href="https://github.com/{{ config('custom_build.repository') }}/commit/{{ $latestSha }}"
                            target="_blank" rel="noopener"
                            title="View changelog commit on GitHub"
                            class="text-xs font-mono text-neutral-400 hover:text-black dark:hover:text-white underline underline-offset-2">
                            {{ substr($currentSha, 0, 7) }} &rarr; {{ substr($latestSha, 0, 7) }}
                        </a>
                    @endif
                @endif
            </div>
        @else
            @if ($updateStarted)
                <span class="inline-flex h-[18px] items-center gap-1 rounded-full bg-warning/15 px-2 text-[9.5px] font-semibold leading-none text-warning ring-1 ring-inset ring-warning/25 animate-pulse">
                    <span class="size-1.5 rounded-full bg-warning"></span>
                    <span>Updating…</span>
                </span>
            @else
                <button type="button" wire:click="update"
                    aria-label="New version available"
                    title="Click to install update ({{ substr($latestSha ?? '', 0, 7) }})"
                    class="inline-flex h-[18px] cursor-pointer items-center rounded-full bg-coollabs/10 px-1.5 text-[9.5px] font-semibold leading-none text-coollabs ring-1 ring-inset ring-coollabs/25 transition-colors hover:bg-coollabs/15 dark:bg-brand/15 dark:text-brand-light dark:ring-brand/25 dark:hover:bg-brand/20">
                    <span wire:loading.remove wire:target="update">New version available</span>
                    <span wire:loading wire:target="update">Starting…</span>
                </button>
            @endif
        @endif
    @else
        @if ($fullButton)
            <x-forms.button type="button" disabled class="opacity-60 cursor-default">
                <x-reicon name="check" class="size-3.5 text-success" />
                <span>Latest version ({{ substr($currentSha ?? 'latest', 0, 7) }})</span>
            </x-forms.button>
        @endif
    @endif
</div>
