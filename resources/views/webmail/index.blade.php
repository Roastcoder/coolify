@php
    $icons = [
        'inbox' => 'M4 13h4l2 3h4l2-3h4M5 5h14l1 8v5a1 1 0 01-1 1H5a1 1 0 01-1-1v-5l1-8z',
        'drafts' => 'M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4',
        'sent' => 'M4 12l16-8-6 16-3-7-7-1z',
        'archive' => 'M4 5h16v4H4zM5 9v10h14V9M10 13h4',
        'junk' => 'M12 3l9 16H3l9-16zM12 10v4M12 17h.01',
        'trash' => 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13',
        null => 'M3 6h6l2 2h10v11H3z',
    ];
    $initials = fn (string $name) => Str::upper(collect(preg_split('/[\s._@-]+/', $name, -1, PREG_SPLIT_NO_EMPTY))->take(2)->map(fn ($part) => Str::substr($part, 0, 1))->implode('')) ?: '?';
    $hue = fn (string $value) => crc32(Str::lower($value)) % 360;
    $when = function (?string $date) {
        if (! $date) {
            return '';
        }
        $date = Carbon\Carbon::parse($date)->timezone(config('app.timezone'));

        return $date->isToday() ? $date->format('H:i') : ($date->isCurrentYear() ? $date->format('j M') : $date->format('j M Y'));
    };
    $folderName = collect($folders)->firstWhere('path', $folder)['name'] ?? 'Inbox';
    $listUrl = fn (array $extra = []) => route('webmail.index', array_filter(['folder' => $folder, 'q' => $search ?: null, 'page' => $page > 1 ? $page : null, ...$extra]));
    $hidden = fn () => '<input type="hidden" name="folder" value="'.e($folder).'"><input type="hidden" name="q" value="'.e($search).'"><input type="hidden" name="page" value="'.e($page).'">';
@endphp
<x-webmail-layout :title="$folderName.' | StandaloneCoders Mail'">
    <div class="flex h-screen flex-col overflow-hidden bg-slate-50">
        {{-- Top bar --}}
        <header class="flex h-14 shrink-0 items-center gap-4 bg-green-800 px-3 text-white sm:px-4">
            <a href="{{ route('webmail.index') }}" class="flex shrink-0 items-center gap-2.5">
                <img src="/standalonecoders-mark.svg" alt="" class="size-7 rounded-md ring-1 ring-white/20">
                <span class="hidden font-semibold sm:inline">StandaloneCoders Mail</span>
            </a>
            <form method="GET" action="{{ route('webmail.index') }}" role="search" class="mx-auto w-full max-w-xl">
                <input type="hidden" name="folder" value="{{ $folder }}">
                <label for="q" class="sr-only">Search mail</label>
                <div class="relative">
                    <svg viewBox="0 0 24 24" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-white/70" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
                    <input id="q" name="q" type="search" value="{{ $search }}" placeholder="Search in {{ $folderName }}"
                        class="h-9 w-full rounded-md border-0 bg-white/15 pl-9 pr-3 text-sm text-white placeholder:text-white/70 focus:bg-white focus:text-slate-900 focus:outline-none focus:ring-2 focus:ring-white/60 focus:placeholder:text-slate-400">
                </div>
            </form>
            <button type="button" onclick="openCompose()" class="flex shrink-0 items-center gap-1.5 rounded-md bg-white/20 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/30 md:hidden" aria-label="New message">
                <svg viewBox="0 0 24 24" class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                <span>New</span>
            </button>
            <details class="relative shrink-0">
                <summary class="flex cursor-pointer list-none items-center rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-white [&::-webkit-details-marker]:hidden" aria-label="Account menu">
                    <span class="grid size-9 place-items-center rounded-full text-sm font-semibold text-white ring-2 ring-white/30" style="background: hsl({{ $hue($email) }} 45% 38%)">{{ $initials(Str::before($email, '@')) }}</span>
                </summary>
                <div class="absolute right-0 z-20 mt-2 w-72 overflow-hidden rounded-xl border border-slate-200 bg-white text-slate-700 shadow-xl">
                    <div class="flex items-center gap-3 border-b border-slate-100 p-4">
                        <span class="grid size-11 shrink-0 place-items-center rounded-full font-semibold text-white" style="background: hsl({{ $hue($email) }} 45% 38%)">{{ $initials(Str::before($email, '@')) }}</span>
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-slate-900">{{ Str::before($email, '@') }}</p>
                            <p class="truncate text-sm text-slate-500">{{ $email }}</p>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('webmail.logout') }}">
                        @csrf
                        <button type="submit" class="w-full px-4 py-3 text-left text-sm font-medium text-red-600 hover:bg-red-50">Sign out</button>
                    </form>
                </div>
            </details>
        </header>

        <div class="flex min-h-0 flex-1">
            {{-- Folders --}}
            <nav aria-label="Folders" class="hidden w-56 shrink-0 overflow-y-auto border-r border-slate-200 bg-white p-2 md:block">
                <div class="mb-3 px-1">
                    <button type="button" onclick="openCompose()"
                        class="flex w-full items-center justify-center gap-2 rounded-lg bg-green-700 px-4 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-green-800 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-green-600">
                        <svg viewBox="0 0 24 24" class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M12 5v14M5 12h14" />
                        </svg>
                        <span>New message</span>
                    </button>
                </div>
                <ul class="space-y-0.5">
                    @foreach ($folders as $item)
                        @php $active = $item['path'] === $folder; @endphp
                        <li>
                            <a href="{{ route('webmail.index', ['folder' => $item['path']]) }}" @if ($active) aria-current="page" @endif
                                class="flex items-center gap-3 rounded-md px-3 py-2 text-sm {{ $active ? 'bg-green-50 font-semibold text-green-900' : 'text-slate-700 hover:bg-slate-100' }}">
                                <svg viewBox="0 0 24 24" class="size-4 shrink-0 {{ $active ? 'text-green-700' : 'text-slate-500' }}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icons[$item['role']] ?? $icons[null] }}" /></svg>
                                <span class="min-w-0 flex-1 truncate">{{ $item['name'] }}</span>
                                @if ($item['unread'] > 0)
                                    <span class="text-xs font-semibold text-green-700">{{ $item['unread'] }}<span class="sr-only"> unread</span></span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            {{-- Message list --}}
            <section aria-label="Messages" class="{{ $message ? 'hidden lg:flex' : 'flex' }} w-full min-w-0 flex-col border-r border-slate-200 bg-white lg:w-[380px] lg:shrink-0">
                <div class="flex gap-1 overflow-x-auto border-b border-slate-200 p-2 md:hidden">
                    <button type="button" onclick="openCompose()"
                        class="shrink-0 inline-flex items-center gap-1.5 rounded-full bg-green-700 px-3 py-1.5 text-sm font-semibold text-white shadow-xs hover:bg-green-800">
                        <svg viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                        <span>New</span>
                    </button>
                    @foreach ($folders as $item)
                        <a href="{{ route('webmail.index', ['folder' => $item['path']]) }}"
                            class="shrink-0 rounded-full px-3 py-1.5 text-sm {{ $item['path'] === $folder ? 'bg-green-100 font-semibold text-green-900' : 'text-slate-600' }}">{{ $item['name'] }}@if ($item['unread'] > 0) ({{ $item['unread'] }})@endif</a>
                    @endforeach
                </div>
                <div class="flex items-baseline justify-between px-4 pb-2 pt-4">
                    <h1 class="text-lg! font-semibold text-slate-900">{{ $search !== '' ? 'Results' : $folderName }}</h1>
                    <span class="text-xs text-slate-500">{{ number_format($total) }} {{ Str::plural('message', $total) }}</span>
                </div>
                @if (session('status'))
                    <p role="status" class="mx-4 mb-2 rounded-md bg-green-50 px-3 py-2 text-sm text-green-800">{{ session('status') }}</p>
                @endif
                <ul class="min-h-0 flex-1 overflow-y-auto">
                    @forelse ($messages as $item)
                        @php $selected = $message && $message['uid'] === $item['uid']; @endphp
                        <li>
                            <a href="{{ $listUrl(['uid' => $item['uid']]) }}" @if ($selected) aria-current="true" @endif
                                class="flex gap-3 border-b border-l-[3px] border-b-slate-100 px-4 py-3 {{ $selected ? 'border-l-green-600 bg-green-50' : ($item['seen'] ? 'border-l-transparent hover:bg-slate-50' : 'border-l-green-500 hover:bg-slate-50') }}">
                                <span class="grid size-9 shrink-0 place-items-center rounded-full text-xs font-semibold text-white" style="background: hsl({{ $hue($item['from_email'] ?: $item['from']) }} 45% 42%)" aria-hidden="true">{{ $initials($item['from']) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-baseline gap-2">
                                        <span class="min-w-0 flex-1 truncate text-sm {{ $item['seen'] ? 'text-slate-700' : 'font-semibold text-slate-900' }}">{{ $item['from'] ?: '(unknown sender)' }}</span>
                                        <span class="shrink-0 text-xs {{ $item['seen'] ? 'text-slate-500' : 'font-semibold text-green-700' }}">{{ $when($item['date']) }}</span>
                                    </span>
                                    <span class="mt-0.5 block truncate text-sm {{ $item['seen'] ? 'text-slate-600' : 'font-medium text-green-800' }}">
                                        @unless ($item['seen'])<span class="sr-only">Unread: </span>@endunless{{ $item['subject'] }}
                                    </span>
                                </span>
                            </a>
                        </li>
                    @empty
                        <li class="px-6 py-16 text-center text-sm text-slate-500">
                            {{ $search !== '' ? 'No messages match your search.' : 'No messages here.' }}
                        </li>
                    @endforelse
                </ul>
                @if ($pages > 1)
                    <nav aria-label="Pages" class="flex items-center justify-between border-t border-slate-200 px-4 py-2 text-sm">
                        @if ($page > 1)
                            <a href="{{ $listUrl(['page' => $page - 1]) }}" class="rounded px-2 py-1 font-medium text-green-700 hover:bg-green-50">Newer</a>
                        @else
                            <span></span>
                        @endif
                        <span class="text-slate-500">Page {{ $page }} of {{ $pages }}</span>
                        @if ($page < $pages)
                            <a href="{{ $listUrl(['page' => $page + 1]) }}" class="rounded px-2 py-1 font-medium text-green-700 hover:bg-green-50">Older</a>
                        @else
                            <span></span>
                        @endif
                    </nav>
                @endif
            </section>

            {{-- Reader --}}
            <main class="{{ $message ? 'flex' : 'hidden lg:flex' }} min-w-0 flex-1 flex-col bg-white lg:bg-slate-50">
                @if ($message)
                    <div class="flex flex-wrap items-center gap-1 border-b border-slate-200 bg-white px-2 py-1.5">
                        <a href="{{ $listUrl() }}" class="rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 lg:hidden">&larr; Back</a>
                        <button type="button" onclick="replyToCurrentMessage()" class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14L4 9l5-5" /><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v2.5" /></svg>
                            Reply
                        </button>
                        <button type="button" onclick="forwardCurrentMessage()" class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 14l5-5-5-5" /><path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5v2.5" /></svg>
                            Forward
                        </button>
                        @foreach (array_filter([$role !== 'archive' && collect($folders)->contains('role', 'archive') ? ['archive', 'Archive', 'M4 5h16v4H4zM5 9v10h14V9M10 13h4'] : null, ['delete', $role === 'trash' ? 'Delete forever' : 'Delete', 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13'], ['unread', 'Mark unread', 'M3 7l9 6 9-6M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z']]) as [$action, $label, $path])
                            <form method="POST" action="{{ route('webmail.messages.update', $message['uid']) }}">
                                @csrf
                                {!! $hidden() !!}
                                <input type="hidden" name="action" value="{{ $action }}">
                                <button type="submit" class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium {{ $action === 'delete' ? 'text-red-600 hover:bg-red-50' : 'text-slate-700 hover:bg-slate-100' }}">
                                    <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $path }}" /></svg>
                                    {{ $label }}
                                </button>
                            </form>
                        @endforeach
                    </div>
                    <article class="flex min-h-0 flex-1 flex-col lg:m-3 lg:overflow-hidden lg:rounded-xl lg:border lg:border-slate-200 lg:bg-white lg:shadow-sm">
                        <header class="border-b border-slate-100 px-5 py-4 sm:px-6">
                            <h2 class="text-xl! font-semibold text-slate-900">{{ $message['subject'] }}</h2>
                            <div class="mt-4 flex items-start gap-3">
                                <span class="grid size-10 shrink-0 place-items-center rounded-full text-sm font-semibold text-white" style="background: hsl({{ $hue($message['from_email'] ?: $message['from']) }} 45% 42%)" aria-hidden="true">{{ $initials($message['from']) }}</span>
                                <div class="min-w-0 flex-1 text-sm">
                                    <div class="flex flex-wrap items-baseline gap-x-2">
                                        <span class="font-semibold text-slate-900">{{ $message['from'] }}</span>
                                        @if ($message['from_email'] && $message['from_email'] !== $message['from'])
                                            <span class="text-slate-500">&lt;{{ $message['from_email'] }}&gt;</span>
                                        @endif
                                        <span class="ml-auto text-xs text-slate-500">{{ $message['date'] ? Carbon\Carbon::parse($message['date'])->timezone(config('app.timezone'))->format('D j M Y, H:i') : '' }}</span>
                                    </div>
                                    @if ($message['to'])
                                        <p class="mt-0.5 truncate text-slate-500">To: {{ $message['to'] }}</p>
                                    @endif
                                    @if ($message['cc'])
                                        <p class="truncate text-slate-500">Cc: {{ $message['cc'] }}</p>
                                    @endif
                                </div>
                            </div>
                            @if ($message['attachments'])
                                <ul class="mt-4 flex flex-wrap gap-2" aria-label="Attachments">
                                    @foreach ($message['attachments'] as $file)
                                        <li>
                                            <a href="{{ route('webmail.attachment', ['uid' => $message['uid'], 'index' => $file['index'], 'folder' => $folder]) }}"
                                                class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:border-green-600 hover:bg-green-50">
                                                <svg viewBox="0 0 24 24" class="size-4 text-slate-500" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12l-8.5 8.5a5 5 0 01-7-7L14 5a3.5 3.5 0 015 5l-8.5 8.5a2 2 0 01-3-3L15 8" /></svg>
                                                <span class="max-w-48 truncate font-medium text-slate-800">{{ $file['name'] }}</span>
                                                <span class="text-xs text-slate-500">{{ Number::fileSize($file['size']) }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </header>
                        {{-- No scripts, forms or same-origin access for mail content; links open in a new tab. --}}
                        <iframe title="Message" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" class="min-h-[60vh] w-full flex-1 bg-white lg:min-h-0"
                            srcdoc="{{ '<!DOCTYPE html><meta charset="utf-8"><base target="_blank"><style>body{margin:20px 24px;font:14px/1.6 ui-sans-serif,system-ui,sans-serif;color:#0f172a;overflow-wrap:anywhere}img{max-width:100%;height:auto}</style>'.$message['html'] }}"></iframe>
                    </article>
                @else
                    <div class="m-auto max-w-xs text-center">
                        <svg viewBox="0 0 24 24" class="mx-auto size-12 text-slate-300" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7l9 6 9-6M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z" /></svg>
                        <p class="mt-3 font-medium text-slate-700">Select a message to read</p>
                        <p class="mt-1 text-sm text-slate-500">Nothing is selected.</p>
                    </div>
                @endif
            </main>
        </div>
    </div>

    {{-- Compose dialog --}}
    <dialog id="compose-dialog"
        class="fixed inset-0 m-auto z-50 w-full max-w-2xl max-h-[90vh] rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-slate-900/50 backdrop:backdrop-blur-xs open:flex open:flex-col overflow-hidden text-slate-700">
        <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-3 shrink-0">
            <h3 id="compose-title" class="text-base font-semibold text-slate-900">New message</h3>
            <button type="button" onclick="closeCompose()" class="rounded-md p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-600 focus:outline-hidden" aria-label="Close dialog">
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12" /></svg>
            </button>
        </div>

        <form method="POST" action="{{ route('webmail.send') }}" enctype="multipart/form-data" class="flex min-h-0 flex-1 flex-col overflow-hidden">
            @csrf
            {!! $hidden() !!}

            @if ($errors->any())
                <div class="mx-5 mt-4 shrink-0 rounded-lg bg-red-50 p-3 text-sm text-red-700 border border-red-200">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="border-b border-slate-100 px-5 py-2.5 shrink-0">
                <div class="flex items-center gap-3">
                    <label for="compose-to" class="w-14 text-xs font-semibold uppercase tracking-wider text-slate-400">To</label>
                    <input type="text" id="compose-to" name="to" value="{{ old('to', request('to')) }}" required placeholder="recipient@example.com"
                        class="flex-1 border-0 bg-transparent py-1 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-hidden focus:ring-0">
                    <button type="button" onclick="toggleCcBcc()" class="text-xs font-medium text-slate-500 hover:text-green-700">Cc / Bcc</button>
                </div>
            </div>

            <div id="compose-cc-group" class="{{ old('cc') || old('bcc') || request('cc') || request('bcc') ? '' : 'hidden' }} border-b border-slate-100 px-5 py-2 space-y-2 bg-slate-50/50 shrink-0">
                <div class="flex items-center gap-3">
                    <label for="compose-cc" class="w-14 text-xs font-semibold uppercase tracking-wider text-slate-400">Cc</label>
                    <input type="text" id="compose-cc" name="cc" value="{{ old('cc', request('cc')) }}" placeholder="cc@example.com"
                        class="flex-1 border-0 bg-transparent py-1 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-hidden focus:ring-0">
                </div>
                <div class="flex items-center gap-3 border-t border-slate-100 pt-2">
                    <label for="compose-bcc" class="w-14 text-xs font-semibold uppercase tracking-wider text-slate-400">Bcc</label>
                    <input type="text" id="compose-bcc" name="bcc" value="{{ old('bcc', request('bcc')) }}" placeholder="bcc@example.com"
                        class="flex-1 border-0 bg-transparent py-1 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-hidden focus:ring-0">
                </div>
            </div>

            <div class="border-b border-slate-100 px-5 py-2.5 shrink-0">
                <div class="flex items-center gap-3">
                    <label for="compose-subject" class="w-14 text-xs font-semibold uppercase tracking-wider text-slate-400">Subject</label>
                    <input type="text" id="compose-subject" name="subject" value="{{ old('subject', request('subject')) }}" placeholder="Subject"
                        class="flex-1 border-0 bg-transparent py-1 text-sm font-medium text-slate-900 placeholder:text-slate-400 focus:outline-hidden focus:ring-0">
                </div>
            </div>

            <div class="min-h-0 flex-1 px-5 py-3 overflow-y-auto">
                <textarea id="compose-body" name="body" rows="10" placeholder="Write your message..."
                    class="h-full min-h-[160px] w-full resize-none border-0 p-0 text-sm leading-relaxed text-slate-800 placeholder:text-slate-400 focus:outline-hidden focus:ring-0">{{ old('body', request('body')) }}</textarea>
            </div>

            <div id="attachments-preview" class="hidden shrink-0 px-5 py-2 border-t border-slate-100 bg-slate-50 text-xs text-slate-600 flex flex-wrap gap-2"></div>

            <div class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-5 py-3 shrink-0">
                <div class="flex items-center gap-2">
                    <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-green-700 px-5 py-2 text-sm font-semibold text-white shadow-xs hover:bg-green-800 focus:outline-hidden focus-visible:ring-2 focus-visible:ring-green-600">
                        <svg viewBox="0 0 24 24" class="size-4 -rotate-45" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M22 2L11 13" />
                            <path d="M22 2l-7 20-4-9-9-4 20-7z" />
                        </svg>
                        Send
                    </button>
                    <label for="compose-attachments" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">
                        <svg viewBox="0 0 24 24" class="size-4 text-slate-500" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 12l-8.5 8.5a5 5 0 01-7-7L14 5a3.5 3.5 0 015 5l-8.5 8.5a2 2 0 01-3-3L15 8" />
                        </svg>
                        Attach files
                        <input type="file" id="compose-attachments" name="attachments[]" multiple class="sr-only" onchange="handleFilesSelected(this)">
                    </label>
                </div>
                <button type="button" onclick="closeCompose()" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-500 hover:text-slate-800 hover:bg-slate-200/60">Discard</button>
            </div>
        </form>
    </dialog>

    <script>
        @if ($message)
            window.currentMessage = {!! json_encode([
                'subject' => $message['subject'],
                'from' => $message['from'],
                'from_email' => $message['from_email'],
                'to' => $message['to'],
                'date' => $message['date'] ? Carbon\Carbon::parse($message['date'])->timezone(config('app.timezone'))->format('D, j M Y H:i') : '',
                'text' => $message['text'] ?? '',
            ]) !!};
        @endif

        function openCompose(data = {}) {
            const dialog = document.getElementById('compose-dialog');
            if (!dialog) return;

            if (data.to !== undefined) document.getElementById('compose-to').value = data.to;
            if (data.subject !== undefined) document.getElementById('compose-subject').value = data.subject;
            if (data.body !== undefined) document.getElementById('compose-body').value = data.body;
            if (data.cc !== undefined) {
                document.getElementById('compose-cc').value = data.cc;
                if (data.cc) toggleCcBcc(true);
            }
            if (data.bcc !== undefined) {
                document.getElementById('compose-bcc').value = data.bcc;
                if (data.bcc) toggleCcBcc(true);
            }
            if (data.title) {
                document.getElementById('compose-title').textContent = data.title;
            } else {
                document.getElementById('compose-title').textContent = 'New message';
            }

            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }

            if (!document.getElementById('compose-to').value) {
                document.getElementById('compose-to').focus();
            } else {
                document.getElementById('compose-body').focus();
            }
        }

        function closeCompose() {
            const dialog = document.getElementById('compose-dialog');
            if (!dialog) return;
            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        }

        function toggleCcBcc(forceShow) {
            const el = document.getElementById('compose-cc-group');
            if (!el) return;
            if (forceShow === true) {
                el.classList.remove('hidden');
            } else {
                el.classList.toggle('hidden');
            }
        }

        function handleFilesSelected(input) {
            const preview = document.getElementById('attachments-preview');
            if (!preview) return;
            preview.innerHTML = '';
            if (!input.files || input.files.length === 0) {
                preview.classList.add('hidden');
                return;
            }
            preview.classList.remove('hidden');
            Array.from(input.files).forEach(file => {
                const chip = document.createElement('span');
                chip.className = 'inline-flex items-center gap-1.5 rounded-md bg-white px-2.5 py-1 border border-slate-200 font-medium text-slate-700';
                chip.textContent = `${file.name} (${Math.round(file.size / 1024)} KB)`;
                preview.appendChild(chip);
            });
        }

        function replyToCurrentMessage() {
            if (!window.currentMessage) return;
            const msg = window.currentMessage;
            const replyTo = msg.from_email || msg.from;
            const reSubject = msg.subject.toLowerCase().startsWith('re:') ? msg.subject : `Re: ${msg.subject}`;
            const quotedBody = `\n\nOn ${msg.date}, ${msg.from} wrote:\n` + (msg.text || '').split('\n').map(l => `> ${l}`).join('\n');
            openCompose({
                to: replyTo,
                subject: reSubject,
                body: quotedBody,
                title: 'Reply'
            });
        }

        function forwardCurrentMessage() {
            if (!window.currentMessage) return;
            const msg = window.currentMessage;
            const fwdSubject = msg.subject.toLowerCase().startsWith('fwd:') ? msg.subject : `Fwd: ${msg.subject}`;
            const fwdBody = `\n\n---------- Forwarded message ---------\nFrom: ${msg.from} <${msg.from_email}>\nDate: ${msg.date}\nSubject: ${msg.subject}\nTo: ${msg.to}\n\n${msg.text || ''}`;
            openCompose({
                subject: fwdSubject,
                body: fwdBody,
                title: 'Forward'
            });
        }

        document.getElementById('compose-dialog')?.addEventListener('click', (e) => {
            const rect = e.target.getBoundingClientRect();
            if (e.target.tagName === 'DIALOG' && (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom)) {
                closeCompose();
            }
        });

        @if ($errors->any() || old('to') || request()->boolean('compose'))
            document.addEventListener('DOMContentLoaded', () => {
                openCompose();
                @if (old('cc') || old('bcc') || request('cc') || request('bcc'))
                    toggleCcBcc(true);
                @endif
            });
        @endif
    </script>
</x-webmail-layout>
