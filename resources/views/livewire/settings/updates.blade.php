<div>
    <x-slot:title>
        Update Settings | StandaloneCoders Cloud
    </x-slot>

    <x-settings.layout>
        {{-- Fork-only: StandaloneCoders Cloud feature highlights shown on the Updates page. --}}
        <div class="rounded-lg border border-coollabs/30 bg-coollabs/5 p-4 text-sm dark:border-brand/25 dark:bg-brand/5">
            <p class="mb-2 font-semibold text-coollabs dark:text-brand-light">✨ What's new in StandaloneCoders Cloud</p>
            <ul class="space-y-1 text-neutral-700 dark:text-fg-faint">
                <li>📬 <strong>StandaloneCoders Mail</strong> — compose, reply, and forward emails right in the dashboard</li>
                <li>📥 <strong>Built-in mail server (Maddy)</strong> — manage mailboxes without a third-party email host</li>
                <li>🌐 <strong>Custom mail domain</strong> — set your own domain under
                    <a href="{{ route('settings.mail-server') }}" class="underline underline-offset-2">Settings › Mail server</a>
                    to access webmail at <code>mail.yourdomain.com</code>
                </li>
                <li>⬆️ <strong>One-click update</strong> — pull the latest build of this fork with the button below</li>
            </ul>
        </div>

        <form wire:submit="submit" class="application-settings-form flex min-w-0 flex-col gap-6">
            {{-- Exclude is_auto_update_enabled (instantSave) so the bar does not flash. --}}
            <x-unsaved-bar action="submit" targets="update_check_frequency,auto_update_frequency" />

            <x-application.settings-section title="Updates"
                helper="Install the latest Coolify version manually when an update is available.">
                <x-slot:actions>
                    <livewire:custom-upgrade :full-button="true" key="settings-upgrade" />
                    <x-forms.button type="button" wire:click="checkManually">
                        <x-reicon name="refresh" class="size-3.5" wire:loading.remove wire:target="checkManually" />
                        <span wire:loading.remove wire:target="checkManually">Check now</span>
                        <span wire:loading wire:target="checkManually">Checking…</span>
                    </x-forms.button>
                </x-slot:actions>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input disabled id="update_channel_display" label="Channel"
                        value="StandaloneCoders Cloud (Roastcoder)"
                        helper="Updates are published from Roastcoder/standalonecoders-cloud." />

                    <x-forms.input required id="update_check_frequency" label="Check frequency"
                        placeholder="0 * * * *"
                        helper="A cron expression or preset such as hourly, daily, weekly, monthly, or yearly." />

                    <x-forms.listbox id="docker_registry_url" label="Docker registry" :options="[
                        ['value' => 'docker.io', 'label' => 'Docker Hub'],
                        ['value' => 'ghcr.io', 'label' => 'GitHub Container Registry'],
                    ]"
                        helper="Switch registries if the current source is rate limited." />

                    @if (!is_null(config('constants.coolify.autoupdate', null)))
                        <x-forms.listbox disabled id="is_auto_update_enabled" label="Automatic updates"
                            helper="Controlled by the AUTOUPDATE environment variable." :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                    @else
                        <x-forms.listbox id="is_auto_update_enabled" label="Automatic updates"
                            onChange="instantSave" :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                    @endif

                    @if (is_null(config('constants.coolify.autoupdate', null)) && $is_auto_update_enabled)
                        <x-forms.input required id="auto_update_frequency" label="Update frequency"
                            placeholder="0 0 * * *"
                            helper="Cron expression or preset for installing updates." />
                    @else
                        <x-forms.input label="Update frequency" disabled placeholder="Disabled" />
                    @endif
                </div>
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
