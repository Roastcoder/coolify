<div class="flex flex-col gap-6" @if ($waiting) wire:poll.1500ms="checkResult" @endif>
    <x-slot:title>
        AI | StandaloneCoders Cloud
    </x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1>AI</h1>
            <p class="text-sm">Ask the AI to check, deploy and fix your servers and apps. Instant: status, apps, down, help.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('settings.ai') }}" class="button" {{ wireNavigate() }}>Settings</a>
        </div>
    </div>

    @unless ($configured)
        <div class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm">
            Add a Claude API key in <a class="underline" href="{{ route('settings.ai') }}" {{ wireNavigate() }}>Settings &gt; AI assistant</a> to turn the assistant on.
        </div>
    @endunless

    <div class="min-w-0">
        <section class="flex h-[calc(100dvh-14rem)] min-h-[28rem] min-w-0 flex-col rounded-xl border border-neutral-200 bg-white dark:border-white/[0.07] dark:bg-panel" aria-label="Chat">
            <header class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-white/[0.07]">
                <h2 class="text-base font-semibold">Chat</h2>
                <button type="button" wire:click="newChat" class="button" @disabled($waiting)>New chat</button>
            </header>
            <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-4" x-data x-init="$el.scrollTop = $el.scrollHeight"
                x-effect="$wire.log; $nextTick(() => $el.scrollTop = $el.scrollHeight)" aria-live="polite">
                @forelse ($log as $entry)
                    <div wire:key="ai-log-{{ $loop->index }}-{{ md5($entry['text']) }}" @class([
                        'max-w-[90%] rounded-2xl px-4 py-2.5 text-sm',
                        'self-end bg-coollabs text-white dark:bg-coollabs dark:text-[#101613]' => $entry['role'] === 'user',
                        'self-start bg-neutral-100 dark:bg-white/[0.05]' => $entry['role'] !== 'user',
                    ])>
                        @if ($entry['role'] === 'user')
                            <p class="whitespace-pre-wrap break-words">{{ $entry['text'] }}</p>
                        @else
                            <div class="prose prose-sm max-w-none break-words dark:prose-invert">{!! Str::markdown($entry['text'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                        @endif
                    </div>
                @empty
                    <div class="m-auto max-w-sm text-center text-sm">
                        <p class="font-medium">Try asking:</p>
                        <p>Which apps are down? · Show the logs of my API · Redeploy the website · How full is the disk on my server?</p>
                    </div>
                @endforelse
                @if ($waiting === 'chat')
                    <div class="self-start rounded-2xl bg-neutral-100 px-4 py-2.5 text-sm dark:bg-white/[0.05]">The AI is working…</div>
                @endif
            </div>

            @if ($pending && ! $waiting)
                <div class="mx-4 mb-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                    <p class="font-medium">The AI wants to:</p>
                    <pre class="mt-1 whitespace-pre-wrap break-words text-xs">{{ $pending }}</pre>
                    <div class="mt-2 flex gap-2">
                        <x-forms.button wire:click="answer(true)" isHighlighted>Allow</x-forms.button>
                        <x-forms.button wire:click="answer(false)">Don't allow</x-forms.button>
                    </div>
                </div>
            @endif

            <form wire:submit="send" class="flex gap-2 border-t border-neutral-200 p-3 dark:border-white/[0.07]">
                <label for="ai-message" class="sr-only">Message</label>
                <textarea id="ai-message" wire:model="message" rows="2" placeholder="Ask about your servers and apps…" class="input flex-1 resize-none"
                    x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send() }" @disabled(! $configured)></textarea>
                <x-forms.button type="submit" :disabled="! $configured || $waiting">Send</x-forms.button>
            </form>
        </section>

    </div>
</div>
