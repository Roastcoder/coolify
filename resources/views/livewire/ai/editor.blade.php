<div class="flex h-full min-h-0 flex-col">
    <x-slot:title>
        Code | StandaloneCoders Cloud
    </x-slot>

    <div class="flex flex-wrap items-center gap-2 border-b border-neutral-200 px-3 py-2 dark:border-white/[0.07]">
        <h1 class="mr-auto text-base font-semibold">VS Code</h1>
        @if ($running)
            <form wire:submit="openProject" class="flex items-end gap-2">
                <div class="w-64"><x-forms.listbox id="project" label="Project" :options="$projects" /></div>
                <x-forms.button type="submit" wire:loading.attr="disabled" wire:target="openProject">
                    <span wire:loading.remove wire:target="openProject">Open</span><span wire:loading wire:target="openProject">Opening…</span>
                </x-forms.button>
            </form>
            <a href="{{ $url }}" target="_blank" rel="noopener" class="button">Open in new tab</a>
            <x-forms.button wire:click="start">Restart</x-forms.button>
            <x-modal-confirmation title="Stop VS Code?" isErrorButton submitAction="stop" buttonTitle="Stop"
                :actions="['Open editors and terminals close. Your files and settings stay.']" :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Stop">
                <x-slot:trigger><button type="button" class="button">Stop</button></x-slot:trigger>
            </x-modal-confirmation>
        @endif
    </div>

    @if ($running)
        <iframe wire:key="vscode-{{ md5($folder) }}" src="{{ $url }}" title="VS Code" class="min-h-0 w-full flex-1 border-0 bg-[#1e1e1e]" allow="clipboard-read; clipboard-write"></iframe>
    @else
        <div class="flex flex-1 items-center justify-center p-6">
            <div class="flex max-w-md flex-col gap-3 rounded-xl border border-neutral-200 bg-white p-6 dark:border-white/[0.07] dark:bg-panel">
                <h2 class="text-lg font-semibold">VS Code in your browser</h2>
                <p class="text-sm">The full VS Code editor, running on your server as root. Your server's files are under <code>/host</code>; for a shell on the server itself, run <code>chroot /host</code> in its terminal.</p>
                <p class="text-sm">The Claude Code extension is installed for you and uses the Claude API key from <a href="{{ route('settings.ai') }}" class="underline" {{ wireNavigate() }}>Settings &gt; AI assistant</a>.</p>
                <p class="text-sm">Only instance admins can open it.</p>
                <x-forms.button wire:click="start" isHighlighted>Start VS Code</x-forms.button>
            </div>
        </div>
    @endif
</div>
