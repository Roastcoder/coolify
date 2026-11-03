<div>
    <x-slot:title>
        Mail Server | Coolify
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form mx-auto flex w-full max-w-none min-w-0 flex-col gap-6">
            <x-application.settings-section title="Mail server"
                description="A small built-in mail server (Maddy) running next to Coolify. Mailboxes work with any mail app over IMAP and SMTP.">
                <x-slot:actions>
                    @if ($domains->isNotEmpty())
                        @if ($running)
                            <x-forms.button wire:click="start">Apply changes</x-forms.button>
                            <x-forms.button wire:click="stop" wire:confirm="Stop the mail server? Mail sent to you bounces back until it runs again.">Stop</x-forms.button>
                        @else
                            <x-forms.button wire:click="start" isHighlighted>Start</x-forms.button>
                        @endif
                    @endif
                </x-slot:actions>

                @if ($domains->isEmpty())
                    <p class="text-sm">Add a domain below to set up the mail server.</p>
                @else
                    <div class="grid gap-4 lg:grid-cols-3">
                        <div>
                            <p class="text-sm font-medium">Status</p>
                            <p class="text-sm {{ $running ? 'text-success' : 'text-warning' }}">{{ $running ? 'Running' : 'Stopped' }}</p>
                        </div>
                        <div>
                            <p class="text-sm font-medium">Server name</p>
                            <p class="text-sm">{{ $hostname }}</p>
                        </div>
                        <div>
                            <p class="text-sm font-medium">Ports</p>
                            <p class="text-sm">{{ implode(', ', $ports) }}</p>
                        </div>
                    </div>
                    <p class="mt-4 text-sm">Mail apps: IMAP {{ $hostname }} port 993 (SSL), SMTP {{ $hostname }} port 465 (SSL) or 587 (STARTTLS). The username is the full email address.</p>
                @endif
            </x-application.settings-section>

            @if ($revealed)
                <x-application.settings-section title="Password for {{ $revealedFor }}">
                    <x-slot:actions>
                        <x-forms.button wire:click="hideRevealed">I saved it</x-forms.button>
                    </x-slot:actions>
                    <p class="mb-3 text-sm">Copy it now. It is not shown again.</p>
                    <div class="w-full sm:w-96">
                        <x-forms.copy-input :text="$revealed" />
                    </div>
                </x-application.settings-section>
            @endif

            <x-application.settings-section title="Domains">
                <x-slot:actions>
                    @if ($domains->isNotEmpty())
                        <x-forms.button wire:click="checkDns">Check DNS</x-forms.button>
                    @endif
                </x-slot:actions>

                <form wire:submit="addDomain" class="flex flex-wrap items-end gap-2">
                    <div class="w-full sm:w-72">
                        <x-forms.input id="newDomain" label="New domain" placeholder="example.com" />
                    </div>
                    <x-forms.button type="submit">Add domain</x-forms.button>
                </form>

                @foreach ($domains as $domain)
                    <div class="mt-6" wire:key="domain-{{ $domain->id }}">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <h4 class="font-semibold">{{ $domain->name }} @if ($loop->first)<span class="text-xs font-normal">(primary)</span>@endif</h4>
                            <x-forms.button wire:click="removeDomain({{ $domain->id }})"
                                wire:confirm="Remove {{ $domain->name }} and its aliases?">Remove</x-forms.button>
                        </div>
                        <p class="mb-2 text-sm">Add these records at your DNS provider.</p>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr>
                                        <th class="py-1 pr-3">Type</th>
                                        <th class="py-1 pr-3">Name</th>
                                        <th class="py-1 pr-3">Value</th>
                                        <th class="py-1">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($dns[$domain->name] ?? [] as $record)
                                        <tr class="align-top">
                                            <td class="py-1 pr-3">{{ $record['type'] }}</td>
                                            <td class="py-1 pr-3 break-all">{{ $record['name'] }}</td>
                                            <td class="py-1 pr-3 font-mono text-xs break-all">
                                                {{ $record['value'] }}
                                                <x-copy-button :value="$record['value']" />
                                            </td>
                                            <td class="py-1 whitespace-nowrap">
                                                @if ($record['ok'] === true)
                                                    <span class="text-success">Found</span>
                                                @elseif ($record['ok'] === false)
                                                    <span class="text-error">Missing</span>
                                                @else
                                                    <span>-</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach

                @if ($domains->isNotEmpty())
                    <p class="mt-4 text-sm">Also set the reverse DNS (PTR) of your server IP to {{ $hostname }} at your hosting provider, or Gmail may mark mail as spam.</p>
                @endif
            </x-application.settings-section>

            @if ($running)
                <x-application.settings-section title="Mailboxes">
                    <form wire:submit="createMailbox" class="flex flex-wrap items-end gap-2">
                        <div class="w-full sm:w-48">
                            <x-forms.input id="newMailbox" label="Address" placeholder="info" />
                        </div>
                        <div class="w-full sm:w-56">
                            <x-forms.listbox id="newMailboxDomain" label="Domain"
                                :options="$domains->map(fn ($d) => ['value' => $d->name, 'label' => '@'.$d->name])->all()" />
                        </div>
                        <x-forms.button type="submit">Create mailbox</x-forms.button>
                    </form>

                    <ul class="mt-4 flex flex-col divide-y divide-neutral-200 dark:divide-white/10">
                        @forelse ($mailboxes as $mailbox)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="mailbox-{{ $mailbox }}">
                                <span class="text-sm">{{ $mailbox }}</span>
                                <div class="flex gap-2">
                                    <x-forms.button wire:click="resetPassword('{{ $mailbox }}')"
                                        wire:confirm="Set a new password for {{ $mailbox }}? Mail apps using the old one stop working.">New password</x-forms.button>
                                    <x-forms.button wire:click="deleteMailbox('{{ $mailbox }}')"
                                        wire:confirm="Delete {{ $mailbox }} and all its mail? This cannot be undone.">Delete</x-forms.button>
                                </div>
                            </li>
                        @empty
                            <li class="py-2 text-sm">No mailboxes yet.</li>
                        @endforelse
                    </ul>
                </x-application.settings-section>

                <x-application.settings-section title="Aliases"
                    description="Extra addresses that deliver to a mailbox, for example postmaster@ or sales@. The mailbox can also send from them.">
                    @if ($mailboxes)
                        <form wire:submit="addAlias" class="flex flex-wrap items-end gap-2">
                            <div class="w-full sm:w-40">
                                <x-forms.input id="aliasLocal" label="Address" placeholder="postmaster" />
                            </div>
                            <div class="w-full sm:w-56">
                                <x-forms.listbox id="aliasDomain" label="Domain"
                                    :options="$domains->map(fn ($d) => ['value' => $d->name, 'label' => '@'.$d->name])->all()" />
                            </div>
                            <div class="w-full sm:w-72">
                                <x-forms.listbox id="aliasDestination" label="Delivers to"
                                    :options="collect($mailboxes)->map(fn ($m) => ['value' => $m, 'label' => $m])->all()" />
                            </div>
                            <x-forms.button type="submit">Add alias</x-forms.button>
                        </form>
                    @else
                        <p class="text-sm">Create a mailbox first.</p>
                    @endif

                    <ul class="mt-4 flex flex-col divide-y divide-neutral-200 dark:divide-white/10">
                        @foreach ($aliases as $alias)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="alias-{{ $alias->id }}">
                                <span class="text-sm">{{ $alias->address }} → {{ $alias->destination }}</span>
                                <x-forms.button wire:click="removeAlias({{ $alias->id }})">Remove</x-forms.button>
                            </li>
                        @endforeach
                    </ul>
                </x-application.settings-section>
            @endif
        </div>
    </x-settings.layout>
</div>
