@php
    $repository = config('custom_build.repository');
    $image = 'ghcr.io/'.strtolower((string) $repository);
    $short = fn (?string $sha) => $sha ? substr($sha, 0, 7) : null;
    $autoUpdateLocked = ! is_null(config('constants.coolify.autoupdate', null));
    $checkPresets = ['Hourly' => '0 * * * *', 'Every 6 hours' => '0 */6 * * *', 'Daily' => '0 0 * * *'];
    $installPresets = ['Every night, 3 AM' => '0 3 * * *', 'Sundays, 3 AM' => '0 3 * * 0', 'Every 6 hours' => '0 */6 * * *'];
@endphp

<div>
    <x-slot:title>
        Updates | StandaloneCoders Cloud
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex min-w-0 flex-col gap-6">
            {{-- Exclude is_auto_update_enabled (instantSave) so the bar does not flash. --}}
            <x-unsaved-bar action="submit" targets="update_check_frequency,auto_update_frequency" />

            {{-- Version: what runs now, what is newest, and the one action that matters. --}}
            <x-application.settings-section title="Version"
                description="This panel runs a build of {{ $repository }}. Every push to main publishes a new build.">
                <x-slot:actions>
                    <x-forms.button type="button" wire:click="checkManually">
                        <x-reicon name="refresh" class="size-3.5" wire:loading.class="animate-spin" wire:target="checkManually" />
                        <span wire:loading.remove wire:target="checkManually">Check now</span>
                        <span wire:loading wire:target="checkManually">Checking…</span>
                    </x-forms.button>
                </x-slot:actions>

                <div wire:init="loadStatus" class="flex flex-col gap-5">
                    <div class="flex flex-wrap items-center gap-4">
                        <div @class([
                            'flex size-11 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset',
                            'bg-neutral-500/10 text-neutral-400 ring-neutral-500/20' => ! $statusLoaded,
                            'bg-success/10 text-success ring-success/25' => $statusLoaded && ! $updateAvailable,
                            'bg-warning/10 text-warning ring-warning/30' => $statusLoaded && $updateAvailable,
                        ])>
                            @if (! $statusLoaded)
                                <x-reicon name="refresh" class="size-5 animate-spin" />
                            @elseif ($updateAvailable)
                                <x-reicon name="server-update" class="size-5" />
                            @else
                                <x-reicon name="check-circle" class="size-5" />
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="text-base font-semibold text-neutral-900 dark:text-white">
                                @if (! $statusLoaded)
                                    Checking for updates…
                                @elseif ($updateAvailable)
                                    A new version is ready
                                @elseif (blank($latestSha))
                                    Could not reach the update server
                                @else
                                    You're up to date
                                @endif
                            </p>
                            <p class="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                                @if ($statusLoaded && $updateAvailable)
                                    Updating restarts this panel for about a minute. Your apps and databases keep running.
                                @elseif ($statusLoaded && blank($latestSha))
                                    Try again in a minute. Your current version keeps working.
                                @else
                                    StandaloneCoders Cloud, build {{ $short($currentSha) ?? 'local' }}
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <livewire:custom-upgrade :full-button="true" key="settings-upgrade" />
                        </div>
                    </div>

                    <dl class="grid gap-px overflow-hidden rounded-lg border border-neutral-200 bg-neutral-200 text-sm sm:grid-cols-2 lg:grid-cols-4 dark:border-white/8 dark:bg-white/8">
                        <div class="bg-white px-4 py-3 dark:bg-[#121212]">
                            <dt class="text-xs text-neutral-500 dark:text-neutral-400">Running</dt>
                            <dd class="mt-1 flex items-center gap-1.5 font-mono text-neutral-900 dark:text-white">
                                @if ($currentSha)
                                    <a href="https://github.com/{{ $repository }}/commit/{{ $currentSha }}" target="_blank" rel="noopener"
                                        class="underline decoration-neutral-300 underline-offset-4 hover:decoration-current dark:decoration-neutral-600">{{ $short($currentSha) }}</a>
                                @else
                                    <span class="text-neutral-400">local build</span>
                                @endif
                            </dd>
                        </div>
                        <div class="bg-white px-4 py-3 dark:bg-[#121212]">
                            <dt class="text-xs text-neutral-500 dark:text-neutral-400">Newest build</dt>
                            <dd class="mt-1 flex items-center gap-1.5 font-mono text-neutral-900 dark:text-white">
                                @if (! $statusLoaded)
                                    <span class="text-neutral-400">…</span>
                                @elseif ($latestSha)
                                    <a href="https://github.com/{{ $repository }}/commit/{{ $latestSha }}" target="_blank" rel="noopener"
                                        class="underline decoration-neutral-300 underline-offset-4 hover:decoration-current dark:decoration-neutral-600">{{ $short($latestSha) }}</a>
                                    @if ($updateAvailable)
                                        <span class="rounded-full bg-warning/15 px-1.5 py-0.5 font-sans text-[10px] font-semibold text-warning-700 dark:text-warning">new</span>
                                    @endif
                                @else
                                    <span class="text-neutral-400">unknown</span>
                                @endif
                            </dd>
                        </div>
                        <div class="bg-white px-4 py-3 dark:bg-[#121212]">
                            <dt class="text-xs text-neutral-500 dark:text-neutral-400">Last checked</dt>
                            <dd class="mt-1 text-neutral-900 dark:text-white">
                                {{ $checkedAt ? \Illuminate\Support\Carbon::parse($checkedAt)->diffForHumans() : '…' }}
                            </dd>
                        </div>
                        <div class="bg-white px-4 py-3 dark:bg-[#121212]">
                            <dt class="text-xs text-neutral-500 dark:text-neutral-400">Source</dt>
                            <dd class="mt-1 truncate text-neutral-900 dark:text-white">
                                <a href="https://github.com/{{ $repository }}/releases" target="_blank" rel="noopener"
                                    class="inline-flex items-center gap-1 hover:underline">
                                    {{ $repository }} <x-reicon name="external-link" class="size-3 text-neutral-400" />
                                </a>
                            </dd>
                        </div>
                    </dl>

                    @if ($statusLoaded && $updateAvailable && $currentSha && $latestSha)
                        <a href="https://github.com/{{ $repository }}/compare/{{ $currentSha }}...{{ $latestSha }}" target="_blank" rel="noopener"
                            class="inline-flex w-fit items-center gap-1.5 text-sm text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-white">
                            <x-reicon name="unordered-list" class="size-3.5" />
                            See what changed ({{ $short($currentSha) }} → {{ $short($latestSha) }})
                        </a>
                    @endif
                </div>
            </x-application.settings-section>

            {{-- Automatic updates --}}
            <x-application.settings-section title="Automatic updates"
                description="Installs the newest build on a schedule. The panel restarts for about a minute; apps keep running.">
                <div class="grid gap-5 lg:grid-cols-2">
                    @if ($autoUpdateLocked)
                        <x-forms.listbox disabled id="is_auto_update_enabled" label="Automatic updates"
                            helper="Controlled by the AUTOUPDATE environment variable." :options="[
                                ['value' => true, 'label' => 'On'],
                                ['value' => false, 'label' => 'Off'],
                            ]" />
                    @else
                        <x-forms.listbox id="is_auto_update_enabled" label="Automatic updates"
                            onChange="instantSave" :options="[
                                ['value' => true, 'label' => 'On, install new builds automatically'],
                                ['value' => false, 'label' => 'Off, I update with the button'],
                            ]" />
                    @endif

                    <div class="flex flex-col gap-2">
                        @if (! $autoUpdateLocked && $is_auto_update_enabled)
                            <x-forms.input required id="auto_update_frequency" label="When to install"
                                placeholder="0 3 * * *"
                                helper="A cron expression, or pick one below. Times use the instance timezone." />
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($installPresets as $label => $cron)
                                    <button type="button" wire:click="$set('auto_update_frequency', '{{ $cron }}')" @class([
                                        'rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition-colors',
                                        'bg-neutral-900 text-white ring-neutral-900 dark:bg-white dark:text-neutral-900 dark:ring-white' => $auto_update_frequency === $cron,
                                        'text-neutral-600 ring-neutral-200 hover:bg-neutral-100 dark:text-neutral-300 dark:ring-white/10 dark:hover:bg-white/5' => $auto_update_frequency !== $cron,
                                    ])>{{ $label }}</button>
                                @endforeach
                            </div>
                        @else
                            <x-forms.input label="When to install" disabled placeholder="Off" />
                        @endif
                    </div>
                </div>
            </x-application.settings-section>

            {{-- Update checks --}}
            <x-application.settings-section title="Update checks"
                description="How often the panel looks for a new build and shows the update badge.">
                <div class="grid gap-5 lg:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        <x-forms.input required id="update_check_frequency" label="Check for updates"
                            placeholder="0 * * * *"
                            helper="A cron expression or a preset such as hourly, daily or weekly." />
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($checkPresets as $label => $cron)
                                <button type="button" wire:click="$set('update_check_frequency', '{{ $cron }}')" @class([
                                    'rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition-colors',
                                    'bg-neutral-900 text-white ring-neutral-900 dark:bg-white dark:text-neutral-900 dark:ring-white' => $update_check_frequency === $cron,
                                    'text-neutral-600 ring-neutral-200 hover:bg-neutral-100 dark:text-neutral-300 dark:ring-white/10 dark:hover:bg-white/5' => $update_check_frequency !== $cron,
                                ])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </x-application.settings-section>

            {{-- Image source --}}
            <x-application.settings-section title="Image source"
                description="Where updates are downloaded from.">
                <div class="grid gap-5 lg:grid-cols-2">
                    <div>
                        <span class="mb-1.5 block text-sm font-medium text-neutral-600 dark:text-neutral-300">Image</span>
                        <div class="flex h-8 items-center gap-2 rounded-lg border border-neutral-200 bg-neutral-50 pl-3 pr-1 font-mono text-sm text-neutral-700 dark:border-white/8 dark:bg-white/3 dark:text-neutral-200">
                            <span class="min-w-0 flex-1 truncate">{{ $image }}:latest</span>
                            <x-copy-button :value="$image.':latest'" label="Copy image name" />
                        </div>
                    </div>

                    <x-forms.listbox id="docker_registry_url" label="Helper images registry" :options="[
                        ['value' => 'docker.io', 'label' => 'Docker Hub'],
                        ['value' => 'ghcr.io', 'label' => 'GitHub Container Registry'],
                    ]"
                        helper="Registry for the helper and proxy images. Switch if the current one is rate limited." />
                </div>
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
