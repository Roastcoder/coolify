{{-- Fork-only: bottom tab bar on phones and tablets. The last tab opens the full menu (the slide-over sheet in layouts.app). --}}
@php
    $teamWide = app(App\Services\Access\AccessResolver::class)->coversWholeTeam(auth()->user(), currentTeam()->id);
    $tabs = array_values(array_filter([
        ['label' => 'Home', 'href' => '/', 'active' => request()->is('/', 'dashboard/*', 'canvas'), 'icon' => 'M3 10.5L12 3l9 7.5V20a1 1 0 01-1 1h-5v-6h-6v6H4a1 1 0 01-1-1v-9.5z'],
        ['label' => 'Projects', 'href' => route('project.index'), 'active' => request()->is('projects', 'projects/*', 'project/*'), 'icon' => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z'],
        $teamWide ? ['label' => 'Servers', 'href' => route('server.index'), 'active' => request()->is('server/*', 'servers', 'servers/*'), 'icon' => 'M4 5h16v6H4zM4 13h16v6H4zM8 8h.01M8 16h.01'] : null,
        $teamWide && auth()->user()?->can('canAccessTerminal') ? ['label' => 'Terminal', 'href' => route('terminal'), 'active' => request()->is('terminal*'), 'navigate' => false, 'icon' => 'M4 5h16v14H4zM8 10l2 2-2 2M13 14h3'] : null,
    ]));
@endphp
@if (isSubscribed() || !isCloud())
    <nav aria-label="Main" class="mobile-tab-bar fixed inset-x-0 bottom-0 z-40 border-t border-neutral-200/80 bg-white/90 backdrop-blur-md lg:hidden dark:border-white/[0.07] dark:bg-panel/90"
        style="padding-bottom: env(safe-area-inset-bottom)">
        <ul class="mx-auto flex max-w-xl items-stretch justify-around px-1">
            @foreach ($tabs as $tab)
                <li class="flex-1">
                    <a href="{{ $tab['href'] }}" @if ($tab['navigate'] ?? true) {{ wireNavigate() }} @endif @if ($tab['active']) aria-current="page" @endif
                        class="mobile-tab {{ $tab['active'] ? 'mobile-tab-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $tab['icon'] }}" /></svg>
                        <span>{{ $tab['label'] }}</span>
                    </a>
                </li>
            @endforeach
            <li class="flex-1">
                <button type="button" x-on:click="open = true" class="mobile-tab w-full" :aria-expanded="open.toString()" aria-label="Open menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
                    <span>Menu</span>
                </button>
            </li>
        </ul>
    </nav>
@endif
