<div @if (in_array($whatsapp['state'], ['starting', 'qr', 'offline'])) wire:poll.5s="loadState" @endif>
    <x-slot:title>
        AI Assistant | StandaloneCoders Cloud
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form mx-auto flex w-full max-w-none min-w-0 flex-col gap-6">
            <form wire:submit="save">
                <x-application.settings-section title="AI assistant"
                    description="The AI manages this instance from the AI page and from WhatsApp. Reading is instant; every change or command asks for YES first. On WhatsApp it also needs the access password.">
                    <x-slot:actions>
                        <x-forms.button type="submit">Save</x-forms.button>
                        <a href="{{ route('ai.index') }}" class="button" {{ wireNavigate() }}>Open AI</a>
                    </x-slot:actions>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input id="apiKey" type="password" label="Claude API key (only for Claude models)" autocomplete="off"
                            :placeholder="$hasApiKey ? 'Saved. Enter a new key to replace it.' : 'sk-ant-…'"
                            helper="From console.anthropic.com. Stored encrypted and never shown again." />
                        <x-forms.listbox id="model" label="Model" :options="$models" />
                        <x-forms.input id="accessPassword" type="password" label="Access password" autocomplete="new-password"
                            :placeholder="$hasAccessPassword ? 'Set. Enter a new one to change it.' : 'At least 8 characters'"
                            helper="For WhatsApp: send 'unlock your-password' in the chat to allow changes for 15 minutes. Use a password you use nowhere else." />
                        <x-forms.input id="rootNumbers" label="Root WhatsApp numbers" placeholder="919876543210"
                            helper="These numbers manage everything, like the owner. Changes still need the access password. Separate several with commas." />
                        <x-forms.input id="meharApiUrl" label="Mehar Advisory API" placeholder="https://api.example.com"
                            helper="Turns on the Mehar WhatsApp bot: dashboard users send 'login' to search loans and payments, check RC and challans and get PDFs. Leave empty to keep it off." />
                    </div>
                    @if (! $hasApiKey && ! str_starts_with($model, \App\Models\AiSettings::LOCAL_PREFIX))
                        <p class="text-sm text-warning">Add a Claude API key, or pick the local model, to turn the assistant on.</p>
                    @endif
                </x-application.settings-section>
            </form>

            <x-application.settings-section title="WhatsApp"
                description="A built-in WhatsApp Web gateway. Link a WhatsApp account by scanning the QR code, then chat with the assistant and get notifications on WhatsApp.">
                <x-slot:actions>
                    @if ($whatsapp['state'] === 'offline')
                        <x-forms.button wire:click="startWhatsapp" isHighlighted>Start</x-forms.button>
                    @else
                        <x-forms.button wire:click="startWhatsapp">Rebuild</x-forms.button>
                        @if ($whatsapp['state'] === 'ready')
                            <x-modal-confirmation title="Unlink WhatsApp?" isErrorButton submitAction="logoutWhatsapp" buttonTitle="Unlink"
                                :actions="['The assistant and notifications stop until you scan a new QR code.']" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Unlink">
                                <x-slot:trigger><button type="button" class="button">Unlink</button></x-slot:trigger>
                            </x-modal-confirmation>
                        @endif
                        <x-forms.button wire:click="stopWhatsapp">Stop</x-forms.button>
                    @endif
                </x-slot:actions>

                <div class="grid gap-6 lg:grid-cols-[auto_1fr]">
                    <div class="flex flex-col items-center gap-2">
                        @if ($whatsapp['state'] === 'qr' && $whatsapp['qr'])
                            <img src="{{ $whatsapp['qr'] }}" alt="QR code to link WhatsApp" class="size-56 rounded-lg bg-white p-2">
                            <p class="max-w-56 text-center text-xs">WhatsApp &gt; Settings &gt; Linked devices &gt; Link a device</p>
                        @else
                            <div class="flex size-56 items-center justify-center rounded-lg border border-dashed border-neutral-300 p-4 text-center text-sm dark:border-white/10">
                                @switch($whatsapp['state'])
                                    @case('ready') Linked to +{{ $whatsapp['me'] }} @break
                                    @case('starting') Starting… @break
                                    @case('disconnected') Disconnected. Restarting… @break
                                    @default Not running
                                @endswitch
                            </div>
                        @endif
                    </div>

                    <div class="flex min-w-0 flex-col gap-3 text-sm">
                        <p><span class="font-medium">Who can chat:</span> root numbers manage everything. Any other user whose profile phone number matches the sender only gets read-only dashboard data of their own teams.</p>
                        <p><span class="font-medium">Your profile phone:</span> {{ $adminPhone ?: 'not set. Add it in your profile to chat from your phone.' }}</p>
                        <p><span class="font-medium">Commands:</span> new (start over), unlock your-password, lock, YES / NO to answer a request.</p>
                        <p class="text-warning">WhatsApp Web automation is not an official WhatsApp API. Use a separate number: WhatsApp can block numbers that send automated messages.</p>
                        @if ($whatsapp['state'] === 'offline' && $buildLog !== '')
                            <pre class="max-h-48 overflow-auto rounded-lg bg-neutral-100 p-3 text-xs dark:bg-black/30">{{ $buildLog }}</pre>
                        @endif
                    </div>
                </div>
            </x-application.settings-section>

            <form wire:submit="saveNotifications">
                <x-application.settings-section title="WhatsApp notifications"
                    description="Deployment, backup, server and task notifications of teams that have an instance admin, sent through the linked WhatsApp account.">
                    <x-slot:actions>
                        <x-forms.button type="submit">Save</x-forms.button>
                        <x-forms.button type="button" wire:click="sendTest" :disabled="$whatsapp['state'] !== 'ready'">Send test</x-forms.button>
                    </x-slot:actions>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.input id="notifyNumbers" label="Send to" placeholder="919876543210, 918765432109"
                            helper="WhatsApp numbers with country code, separated by commas." />
                        <x-forms.checkbox id="notificationsEnabled" label="Send notifications on WhatsApp" />
                    </div>
                </x-application.settings-section>
            </form>
        </div>
    </x-settings.layout>
</div>
