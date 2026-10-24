<div x-data="{ modalOpen: false }" x-init="$wire.checkUpdate()"
    @if ($isUpdateAvailable) title="A new build of your Coolify fork is ready" @endif>
    @if ($isUpdateAvailable || $updateStarted)
        @if ($fullButton)
            <x-forms.button type="button" @click="modalOpen = true" isHighlighted>
                {{ $updateStarted ? 'Updating…' : 'Update now' }}
            </x-forms.button>
        @else
            <button type="button" @click="modalOpen = true"
                aria-label="{{ $updateStarted ? 'Update in progress' : 'Update available' }}"
                class="inline-flex h-[18px] cursor-pointer items-center rounded-full bg-coollabs/10 px-1.5 text-[9.5px] font-semibold leading-none text-coollabs ring-1 ring-inset ring-coollabs/25 transition-colors hover:bg-coollabs/15 dark:bg-brand/15 dark:text-brand-light dark:ring-brand/25 dark:hover:bg-brand/20">
                {{ $updateStarted ? 'Updating' : 'Update available' }}
            </button>
        @endif

        <template x-teleport="body">
            <div x-show="modalOpen" x-cloak @keydown.escape.window="modalOpen = false"
                class="fixed inset-0 z-99 flex min-h-full items-center justify-center overflow-y-auto p-4">
                <div x-show="modalOpen" x-transition.opacity @click="modalOpen = false"
                    class="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-[2px]"></div>
                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition
                    role="dialog" aria-modal="true" aria-labelledby="custom-upgrade-title"
                    class="application-settings-form application-settings-section relative flex w-full flex-col lg:max-w-lg"
                    style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                    <header class="flex-nowrap!">
                        <div class="min-w-0 flex-1">
                            <h3 id="custom-upgrade-title" class="truncate">
                                {{ $updateStarted ? 'Update started' : 'Update to your latest build' }}
                            </h3>
                            <p class="mt-0.5 font-mono text-[12px] leading-4" style="color: var(--coollabs-subtle)">
                                {{ \Illuminate\Support\Str::limit($currentSha, 7, '') }}
                                <span class="mx-0.5">&rarr;</span>
                                <a href="https://github.com/{{ config('custom_build.repository') }}/commit/{{ $latestSha }}"
                                    target="_blank" rel="noopener" class="underline underline-offset-2">
                                    {{ \Illuminate\Support\Str::limit($latestSha, 7, '') }}</a>
                            </p>
                        </div>
                        <button type="button" @click="modalOpen = false" aria-label="Close"
                            class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                            <x-reicon name="x" class="size-4" />
                        </button>
                    </header>
                    <div class="application-settings-section-body flex flex-col gap-4 text-sm">
                        @if ($updateStarted)
                            <p>The server is downloading the new image. Coolify restarts in about a minute, then
                                reload this page. Your applications keep running.</p>
                        @else
                            <p>Installs the newest image built from
                                <a href="https://github.com/{{ config('custom_build.repository') }}" target="_blank"
                                    rel="noopener" class="underline underline-offset-2">{{ config('custom_build.repository') }}</a>.
                                Only the Coolify dashboard restarts, for about a minute. Your applications keep running.</p>
                            <div class="flex justify-end gap-2">
                                <x-forms.button type="button" @click="modalOpen = false">Cancel</x-forms.button>
                                <x-forms.button type="button" wire:click="update" isHighlighted>
                                    <span wire:loading.remove wire:target="update">Update now</span>
                                    <span wire:loading wire:target="update">Starting…</span>
                                </x-forms.button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </template>
    @endif
</div>
