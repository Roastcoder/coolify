<div class="application-settings-form w-full">
    <x-slot:title>
        Dashboard | StandaloneCoders Cloud
    </x-slot>

    @if (session('error'))
        <span x-data x-init="$wire.dispatch('error', @js(session('error')))" />
    @endif

    @php
        $dashboardItemLimit = \App\Livewire\Dashboard::ITEM_LIMIT;
        $hasTrafficAnalytics = $servers->contains(fn ($server) => $server->isTrafficAnalyticsEnabled());
        $totalProjects = $projects->count();
        $totalEnvironments = $projects->sum(fn ($p) => $p->environments->count());
        $totalResources = $projects->sum(fn ($p) => $projectResourceCounts[$p->id] ?? 0);
        $totalServers = $servers->count();
        $healthyServers = $servers->filter(fn ($s) => ($serverStatuses[$s->id][1] ?? '') === 'success')->count();

        $iconButton = 'card-icon-button flex size-7 items-center justify-center rounded-lg text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.08] dark:hover:text-fg';
        $titleIconButton = 'flex size-6 items-center justify-center rounded-lg text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg';
        $tableShell = 'overflow-x-auto rounded-2xl border border-neutral-200/80 bg-white/90 shadow-sm backdrop-blur-md dark:border-white/[0.08] dark:bg-[#131c2e]/60';
        $tableHead = 'border-b border-neutral-200/80 bg-neutral-50/80 px-4 py-3 text-[11px] font-semibold uppercase tracking-wider text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.03] dark:text-fg-faint';
        $tableRow = 'min-h-14 items-center border-b border-neutral-200/60 px-4 py-3 text-[12.5px] transition-colors last:border-b-0 hover:bg-neutral-50/80 dark:border-white/[0.05] dark:hover:bg-white/[0.03]';
    @endphp

    <div class="flex min-w-0 flex-col gap-7">
        {{-- ==================== PENDING INVITATIONS ==================== --}}
        @if ($pendingInvitations->isNotEmpty())
            <div class="flex min-w-0 flex-col gap-2">
                @foreach ($pendingInvitations as $invitation)
                    <x-callout type="info" title="Pending team invitation"
                        wire:key="dashboard-invitation-{{ $invitation->uuid }}">
                        <div class="flex min-w-0 flex-wrap items-center justify-between gap-2">
                            <span class="min-w-0">Team <span class="font-semibold">{{ $invitation->team->name }}</span>
                                invited you as {{ ucfirst($invitation->role) }}.</span>
                            <a href="{{ route('team.invitation.show', $invitation->uuid) }}" class="button shrink-0">
                                Review invitation
                            </a>
                        </div>
                    </x-callout>
                @endforeach
            </div>
        @endif

        {{-- ==================== WELCOME & COMMAND CENTER HERO ==================== --}}
        <div class="sc-dashboard-hero p-5 sm:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 flex-col gap-1.5">
                    <div class="flex items-center gap-2">
                        <span class="sc-pill bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20">
                            <span class="sc-pulse-dot size-2 bg-emerald-500"></span>
                            <span>Infrastructure Online</span>
                        </span>
                        <span class="text-xs text-neutral-400 dark:text-neutral-500">•</span>
                        <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">
                            Cloud Console
                        </span>
                    </div>
                    <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">
                        Welcome back{{ auth()->user()?->name ? ', ' . explode(' ', trim(auth()->user()->name))[0] : '' }}
                    </h1>
                    <p class="text-xs sm:text-[13px] text-neutral-600 dark:text-neutral-400 max-w-2xl">
                        Monitor, deploy, and scale your applications, standalone databases, and built-in mail services from one centralized cloud console.
                    </p>
                </div>

                {{-- Fast Action Launcher Pills --}}
                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    @can('createAnyResource')
                        <x-modal-input title="New Project">
                            <x-slot:content>
                                <button type="button" class="button button-highlighted shadow-sm inline-flex items-center gap-1.5 px-3.5 h-9 rounded-xl text-xs font-semibold">
                                    <x-reicon name="plus" class="size-3.5" />
                                    <span>New Project</span>
                                </button>
                            </x-slot:content>
                            <livewire:project.add-empty />
                        </x-modal-input>
                        <a href="{{ route('server.create') }}" {{ wireNavigate() }}
                            class="button shadow-sm inline-flex items-center gap-1.5 px-3.5 h-9 rounded-xl text-xs font-semibold">
                            <x-reicon name="plus" class="size-3.5" />
                            <span>New Server</span>
                        </a>
                    @endcan
                    <a href="{{ route('webmail.index') }}" {{ wireNavigate() }}
                        class="button shadow-sm inline-flex items-center gap-1.5 px-3 h-9 rounded-xl text-xs font-medium text-neutral-700 dark:text-neutral-300">
                        <x-reicon name="mail" class="size-3.5 text-blue-500" />
                        <span>Webmail</span>
                    </a>
                    @if (auth()->user()?->can('canAccessTerminal'))
                        <a href="{{ route('terminal') }}"
                            class="button shadow-sm inline-flex items-center gap-1.5 px-3 h-9 rounded-xl text-xs font-medium text-neutral-700 dark:text-neutral-300">
                            <x-reicon name="browser-terminal" class="size-3.5 text-emerald-500" />
                            <span>Terminal</span>
                        </a>
                    @endif
                </div>
            </div>
        </div>

        {{-- ==================== EXECUTIVE METRIC STAT CARDS ==================== --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {{-- Stat 1: Projects --}}
            @php
                $allProjectsRoute = $projects->count() > $dashboardItemLimit ? route('project.index') : null;
            @endphp
            <div class="sc-stat-card group">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-semibold tracking-wider uppercase text-neutral-500 dark:text-neutral-400">
                        Projects
                    </span>
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 group-hover:scale-105 transition-transform">
                        <x-reicon name="projects" class="size-4" />
                    </div>
                </div>
                <div class="mt-3 flex items-baseline justify-between gap-2">
                    <span class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">
                        {{ $totalProjects }}
                    </span>
                    <span class="text-xs text-neutral-500 dark:text-neutral-400">
                        {{ $totalEnvironments }} {{ $totalEnvironments === 1 ? 'environment' : 'environments' }}
                    </span>
                </div>
                <div class="mt-2.5 flex items-center justify-between border-t border-neutral-100 pt-2 text-[11px] text-neutral-500 dark:border-white/[0.05] dark:text-neutral-400">
                    <span>Workspaces</span>
                    @if ($allProjectsRoute)
                        <a href="{{ $allProjectsRoute }}" {{ wireNavigate() }} class="font-medium text-emerald-600 dark:text-emerald-400 hover:underline">View all ›</a>
                    @else
                        <span class="font-mono text-[10.5px] text-neutral-400">Active</span>
                    @endif
                </div>
            </div>

            {{-- Stat 2: Resources --}}
            <div class="sc-stat-card">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-semibold tracking-wider uppercase text-neutral-500 dark:text-neutral-400">
                        Resources
                    </span>
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
                        <x-reicon name="layers" class="size-4" />
                    </div>
                </div>
                <div class="mt-3 flex items-baseline justify-between gap-2">
                    <span class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">
                        {{ $totalResources }}
                    </span>
                    <span class="text-xs text-neutral-500 dark:text-neutral-400">
                        Active Services
                    </span>
                </div>
                <div class="mt-2.5 flex items-center justify-between border-t border-neutral-100 pt-2 text-[11px] text-neutral-500 dark:border-white/[0.05] dark:text-neutral-400">
                    <span>Apps, DBs & Stacks</span>
                    <span class="font-mono text-[10.5px] text-neutral-400">Across all envs</span>
                </div>
            </div>

            {{-- Stat 3: Servers --}}
            <a href="/servers" {{ wireNavigate() }} class="sc-stat-card group">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-semibold tracking-wider uppercase text-neutral-500 dark:text-neutral-400">
                        Infrastructure
                    </span>
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20 group-hover:scale-105 transition-transform">
                        <x-reicon name="servers" class="size-4" />
                    </div>
                </div>
                <div class="mt-3 flex items-baseline justify-between gap-2">
                    <span class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">
                        {{ $totalServers }}
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        <span class="size-1.5 rounded-full bg-emerald-500"></span>
                        {{ $healthyServers }} Healthy
                    </span>
                </div>
                <div class="mt-2.5 flex items-center justify-between border-t border-neutral-100 pt-2 text-[11px] text-neutral-500 dark:border-white/[0.05] dark:text-neutral-400">
                    <span>SSH Managed Hosts</span>
                    <span class="font-medium text-purple-600 dark:text-purple-400 group-hover:underline">Manage ›</span>
                </div>
            </a>

            {{-- Stat 4: StandaloneCoders Mail Server --}}
            <a href="{{ route('settings.mail-server') }}" {{ wireNavigate() }} class="sc-stat-card group">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-semibold tracking-wider uppercase text-neutral-500 dark:text-neutral-400">
                        Mail Server
                    </span>
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border border-cyan-500/20 group-hover:scale-105 transition-transform">
                        <x-reicon name="mail" class="size-4" />
                    </div>
                </div>
                <div class="mt-3 flex items-baseline justify-between gap-2">
                    <span class="text-[17px] font-bold tracking-tight text-neutral-900 dark:text-white">
                        Maddy + Webmail
                    </span>
                    <span class="sc-pill bg-cyan-500/10 text-cyan-700 dark:text-cyan-300 border border-cyan-500/20 text-[10px]">
                        Built-in
                    </span>
                </div>
                <div class="mt-2.5 flex items-center justify-between border-t border-neutral-100 pt-2 text-[11px] text-neutral-500 dark:border-white/[0.05] dark:text-neutral-400">
                    <span>Custom Domains</span>
                    <span class="font-medium text-cyan-600 dark:text-cyan-400 group-hover:underline">Configure ›</span>
                </div>
            </a>
        </div>

        {{-- ==================== MAIN CONTENT & ACTIVITY RAIL ==================== --}}
        <div class="grid min-w-0 gap-8 xl:items-start xl:has-[.dashboard-activity_section]:grid-cols-[minmax(0,1fr)_22rem] 2xl:has-[.dashboard-activity_section]:grid-cols-[minmax(0,1fr)_26rem]">
            {{-- Activity column (deployments, traffic) --}}
            <div class="dashboard-activity hidden min-w-0 has-[section]:flex flex-col gap-8 xl:order-last">
                <livewire:dashboard.active-deployments />

                @if ($hasTrafficAnalytics)
                    <livewire:dashboard.traffic-analytics />
                @endif
            </div>

            {{-- Projects & Servers Column --}}
            <div class="flex min-w-0 flex-col gap-8">
                {{-- Projects Section --}}
                <section class="@container mb-0! min-w-0" x-data="{
                    viewMode: @js($this->listView('dashboard_projects', 'table')),
                    setViewMode(mode) {
                        this.viewMode = mode;
                        $wire.saveListView('dashboard_projects', mode);
                    }
                }">
                    <x-section-heading title="Projects"
                        :href="$projects->count() > $dashboardItemLimit ? route('project.index') : null">
                        @can('createAnyResource')
                            <x-slot:titleAction>
                                <x-modal-input title="New Project">
                                    <x-slot:content>
                                        <button type="button" class="{{ $titleIconButton }}" title="New project"
                                            aria-label="New project">
                                            <x-reicon name="plus" class="size-3.5" />
                                        </button>
                                    </x-slot:content>
                                    <livewire:project.add-empty />
                                </x-modal-input>
                            </x-slot:titleAction>
                        @endcan
                        <x-slot:actions>
                            <x-canvas-view-toggle :views="$dashboardProjects->isNotEmpty() ? null : []"
                                :list-url="route('dashboard')" :canvas-url="route('dashboard.canvas')" />
                        </x-slot:actions>
                    </x-section-heading>

                    @if ($dashboardProjects->isEmpty())
                        <x-empty title="No projects yet"
                            description="Create your first deployment workspace."
                            icon-name="projects" size="sm" />
                    @else
                        {{-- Projects Grid View --}}
                        <div x-cloak x-show="viewMode === 'grid'"
                            class="grid min-w-0 grid-cols-1 gap-4 @lg:grid-cols-2 @4xl:grid-cols-4">
                            @foreach ($dashboardProjects as $project)
                                @php
                                    $firstEnvironment = $project->environments->first();
                                    $resourceCount = $projectResourceCounts[$project->id];
                                @endphp

                                <article
                                    class="group relative flex min-h-32 min-w-0 flex-col rounded-2xl border border-neutral-200/85 bg-white/95 p-4 shadow-sm backdrop-blur-sm transition-all duration-200 hover:-translate-y-1 hover:border-emerald-500/40 hover:shadow-lg dark:border-white/[0.08] dark:bg-[#131c2e]/70 dark:hover:border-emerald-500/30 dark:hover:shadow-black/40">
                                    <a href="{{ $project->navigateTo() }}" {{ wireNavigate() }}
                                        class="absolute inset-0 rounded-2xl"
                                        aria-label="Open {{ $project->name }}"></a>

                                    <div class="flex min-w-0 items-start gap-3">
                                        <div
                                            class="resource-glyph flex size-9 shrink-0 items-center justify-center rounded-xl border border-neutral-200 bg-neutral-50 text-neutral-700 shadow-xs dark:border-white/10 dark:bg-white/[0.05] dark:text-fg">
                                            @if ($project->icon_path)
                                                <img src="{{ project_icon_url($project) }}"
                                                    alt="{{ $project->name }} icon"
                                                    class="h-full w-full rounded-xl object-cover">
                                            @else
                                                <x-reicon name="projects" class="size-4.5 text-emerald-600 dark:text-emerald-400" />
                                            @endif
                                        </div>
                                        <div class="min-w-0 flex-1 self-center">
                                            <h3
                                                class="truncate text-[13.5px] leading-5 font-semibold text-neutral-900 group-hover:text-emerald-600 dark:text-white dark:group-hover:text-emerald-400 transition-colors">
                                                {{ $project->name }}
                                            </h3>
                                            @if (filled($project->description))
                                                <p class="mt-0.5 truncate text-[11.5px] text-neutral-500 dark:text-fg-faint">
                                                    {{ $project->description }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="mt-auto flex items-center justify-between gap-3 border-t border-neutral-100 pt-3 dark:border-white/[0.06]">
                                        <div class="relative z-10 flex min-w-0 items-center gap-1.5">
                                            <span class="sc-pill bg-neutral-100 text-neutral-600 dark:bg-white/[0.06] dark:text-neutral-300 font-mono text-[11px]">
                                                {{ $project->environments->count() }} env
                                            </span>
                                            <span class="sc-pill bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border border-emerald-500/20 font-mono text-[11px]">
                                                {{ $resourceCount }} res
                                            </span>
                                        </div>

                                        <div class="relative z-10 flex shrink-0 items-center gap-1">
                                            @if ($firstEnvironment)
                                                @can('createAnyResource')
                                                    <a href="{{ route('project.resource.create', [
                                                        'project_uuid' => $project->uuid,
                                                        'environment_uuid' => $firstEnvironment->uuid,
                                                    ]) }}"
                                                        {{ wireNavigate() }}
                                                        class="{{ $iconButton }}"
                                                        title="Add resource"
                                                        aria-label="Add resource to {{ $project->name }}">
                                                        <x-reicon name="plus" class="size-3.5" />
                                                    </a>
                                                @endcan
                                            @endif
                                            @can('update', $project)
                                                <a href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}"
                                                    {{ wireNavigate() }}
                                                    class="{{ $iconButton }}"
                                                    title="Project settings"
                                                    aria-label="Open settings for {{ $project->name }}">
                                                    <x-reicon name="settings" class="size-3.5" />
                                                </a>
                                            @endcan
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>

                        {{-- Projects Table View --}}
                        <div x-show="viewMode === 'table'" class="{{ $tableShell }} transition-opacity" wire:loading.class="opacity-50 pointer-events-none"
                            wire:target="setProjectSort">
                            <div class="grid grid-cols-[minmax(0,1fr)_7rem_4.5rem] gap-4 @2xl:min-w-[560px] @2xl:grid-cols-[minmax(0,1.2fr)_7rem_minmax(0,1.5fr)_4.5rem] {{ $tableHead }}">
                                <x-table.sort-header label="Project" asc="name-asc" desc="name-desc"
                                    action="setProjectSort" :current="$projectSort" />
                                <x-table.sort-header label="Contents" asc="resources-asc" desc="resources-desc" first="desc"
                                    action="setProjectSort" :current="$projectSort" title="Sort by resource count" />
                                <div class="hidden @2xl:block">
                                    <x-table.sort-header label="Description" asc="description-asc" desc="description-desc"
                                        action="setProjectSort" :current="$projectSort" />
                                </div>
                                <span class="text-right">Actions</span>
                            </div>
                            @foreach ($dashboardProjects as $project)
                                @php
                                    $firstEnvironment = $project->environments->first();
                                @endphp
                                <div wire:key="dashboard-project-row-{{ $project->uuid }}"
                                    class="relative grid grid-cols-[minmax(0,1fr)_7rem_4.5rem] gap-4 @2xl:min-w-[560px] @2xl:grid-cols-[minmax(0,1.2fr)_7rem_minmax(0,1.5fr)_4.5rem] {{ $tableRow }}">
                                    <a href="{{ $project->navigateTo() }}" {{ wireNavigate() }}
                                        class="absolute inset-0" aria-label="Open {{ $project->name }}"></a>
                                    <div class="flex min-w-0 items-center gap-3">
                                        <div
                                            class="resource-glyph flex size-8 shrink-0 items-center justify-center rounded-xl border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/10 dark:bg-white/[0.04]">
                                            @if ($project->icon_path)
                                                <img src="{{ project_icon_url($project) }}" alt="{{ $project->name }} icon"
                                                    class="h-full w-full rounded-xl object-cover">
                                            @else
                                                <x-reicon name="projects" class="size-4 text-emerald-600 dark:text-emerald-400" />
                                            @endif
                                        </div>
                                        <p class="truncate text-[13px] font-semibold text-neutral-900 dark:text-fg">
                                            {{ $project->name }}
                                        </p>
                                    </div>
                                    <div class="relative z-10 flex w-fit items-center gap-2 font-medium text-neutral-600 dark:text-fg-dim">
                                        <span class="sc-pill bg-neutral-100 text-neutral-600 dark:bg-white/[0.06] dark:text-neutral-300 font-mono text-[11px]" data-tooltip="Environments">
                                            <x-reicon name="layers" class="size-3 text-neutral-400 dark:text-fg-faint" />
                                            {{ $project->environments->count() }}
                                        </span>
                                        <span class="sc-pill bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border border-emerald-500/20 font-mono text-[11px]" data-tooltip="Resources">
                                            <x-reicon name="grid" class="size-3 text-emerald-500" />
                                            {{ $projectResourceCounts[$project->id] }}
                                        </span>
                                    </div>
                                    <p class="hidden truncate text-neutral-500 @2xl:block dark:text-fg-dim text-xs">
                                        {{ filled($project->description) ? $project->description : '-' }}
                                    </p>
                                    <div class="relative z-10 flex items-center justify-end gap-1">
                                        @if ($firstEnvironment)
                                            @can('createAnyResource')
                                                <a href="{{ route('project.resource.create', [
                                                    'project_uuid' => $project->uuid,
                                                    'environment_uuid' => $firstEnvironment->uuid,
                                                ]) }}"
                                                    {{ wireNavigate() }} class="{{ $iconButton }}" title="Add resource"
                                                    aria-label="Add resource to {{ $project->name }}">
                                                    <x-reicon name="plus" class="size-3.5" />
                                                </a>
                                            @endcan
                                        @endif
                                        @can('update', $project)
                                            <a href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}"
                                                {{ wireNavigate() }} class="{{ $iconButton }}" title="Project settings"
                                                aria-label="Open settings for {{ $project->name }}">
                                                <x-reicon name="settings" class="size-3.5" />
                                            </a>
                                        @endcan
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                {{-- Servers Section --}}
                @if ($showsTeamSections)
                <section class="@container mb-0! min-w-0" x-data="{
                    viewMode: @js($this->listView('dashboard_servers', 'table')),
                    setViewMode(mode) {
                        this.viewMode = mode;
                        $wire.saveListView('dashboard_servers', mode);
                    }
                }">
                    <x-section-heading title="Servers"
                        :href="$servers->count() > $dashboardItemLimit ? route('server.index') : null">
                        @can('createAnyResource')
                            <x-slot:titleAction>
                                <a href="{{ route('server.create') }}" {{ wireNavigate() }} class="{{ $titleIconButton }}"
                                    title="New server" aria-label="New server">
                                    <x-reicon name="plus" class="size-3.5" />
                                </a>
                            </x-slot:titleAction>
                        @endcan
                        @if ($dashboardServers->isNotEmpty())
                            <x-slot:actions>
                                <x-canvas-view-toggle />
                            </x-slot:actions>
                        @endif
                    </x-section-heading>

                    @if ($dashboardServers->isEmpty())
                        @if ($privateKeys->isEmpty())
                            <x-empty title="A private key is required"
                                description="Add an SSH private key before connecting your first server."
                                icon-name="keys" size="sm">
                                @can('create', App\Models\PrivateKey::class)
                                    <x-slot:contents>
                                        <a href="{{ route('security.private-key.index') }}" {{ wireNavigate() }}
                                            class="button button-highlighted">
                                            <x-reicon name="plus" class="size-3.5" />
                                            Add private key
                                        </a>
                                    </x-slot:contents>
                                @endcan
                            </x-empty>
                        @else
                            <x-empty title="No servers yet"
                                description="Connect infrastructure for your deployments."
                                icon-name="servers" size="sm">
                                @can('createAnyResource')
                                    <x-slot:contents>
                                        <a href="{{ route('server.create') }}" {{ wireNavigate() }}
                                            class="button button-highlighted">
                                            <x-reicon name="plus" class="size-3.5" />
                                            New server
                                        </a>
                                    </x-slot:contents>
                                @endcan
                            </x-empty>
                        @endif
                    @else
                        {{-- Servers Grid View --}}
                        <div x-cloak x-show="viewMode === 'grid'"
                            class="grid min-w-0 grid-cols-1 gap-4 @lg:grid-cols-2 @4xl:grid-cols-4">
                            @foreach ($dashboardServers as $server)
                                @php
                                    [$serverStatus, $serverStatusType] = $serverStatuses[$server->id];
                                @endphp

                                <a href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}"
                                    {{ wireNavigate() }} aria-label="Open {{ $server->name }}"
                                    class="group relative flex min-h-32 min-w-0 flex-col rounded-2xl border border-neutral-200/85 bg-white/95 p-4 shadow-sm backdrop-blur-sm transition-all duration-200 hover:-translate-y-1 hover:border-emerald-500/40 hover:shadow-lg dark:border-white/[0.08] dark:bg-[#131c2e]/70 dark:hover:border-emerald-500/30 dark:hover:shadow-black/40">
                                    @if ($server->isMetricsEnabled())
                                        <livewire:dashboard.server-metrics-chart :server="$server"
                                            :key="'dashboard-server-metrics-'.$server->uuid" />
                                    @endif

                                    <div class="relative z-10 flex min-w-0 items-start gap-3">
                                        <div
                                            class="resource-glyph flex size-9 shrink-0 items-center justify-center rounded-xl border border-neutral-200 bg-neutral-50 text-neutral-700 shadow-xs dark:border-white/10 dark:bg-white/[0.05] dark:text-fg">
                                            <x-reicon name="servers" class="size-4.5 text-purple-600 dark:text-purple-400" />
                                        </div>
                                        <div class="min-w-0 flex-1 self-center">
                                            <h3
                                                class="truncate text-[13.5px] leading-5 font-semibold text-neutral-900 group-hover:text-emerald-600 dark:text-white dark:group-hover:text-emerald-400 transition-colors">
                                                {{ $server->name }}
                                            </h3>
                                            @if (filled($server->description))
                                                <p class="mt-0.5 truncate text-[11.5px] text-neutral-500 dark:text-fg-faint">
                                                    {{ $server->description }}
                                                </p>
                                            @endif
                                        </div>

                                        {{-- Server Status Badge with Halo --}}
                                        <span class="sc-pill text-[10.5px] shrink-0 font-medium @if($serverStatusType === 'success') bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20 @elseif($serverStatusType === 'warning') bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20 @else bg-red-500/10 text-red-700 dark:text-red-400 border border-red-500/20 @endif">
                                            <span class="sc-pulse-dot size-1.5 @if($serverStatusType === 'success') bg-emerald-500 @elseif($serverStatusType === 'warning') bg-amber-500 @else bg-red-500 @endif"></span>
                                            <span>{{ $serverStatus }}</span>
                                        </span>
                                    </div>
                                </a>
                            @endforeach
                        </div>

                        {{-- Servers Table View --}}
                        @php
                            $showServerUsage = $dashboardServers->contains(fn ($server) => $server->isMetricsEnabled());
                            $serverTableColumns = $showServerUsage
                                ? 'grid-cols-[minmax(0,1fr)_9.5rem] @xl:grid-cols-[minmax(0,1fr)_9rem_9.5rem] @2xl:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_9rem_9.5rem]'
                                : 'grid-cols-[minmax(0,1fr)_9.5rem] @2xl:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_9.5rem]';
                        @endphp
                        <div x-show="viewMode === 'table'" class="{{ $tableShell }} transition-opacity" wire:loading.class="opacity-50 pointer-events-none"
                            wire:target="setServerSort">
                            <div class="grid {{ $serverTableColumns }} gap-4 @2xl:min-w-[480px] {{ $tableHead }}">
                                <x-table.sort-header label="Server" asc="name-asc" desc="name-desc"
                                    action="setServerSort" :current="$serverSort" />
                                <div class="hidden @2xl:block">
                                    <x-table.sort-header label="Description" asc="description-asc" desc="description-desc"
                                        action="setServerSort" :current="$serverSort" />
                                </div>
                                @if ($showServerUsage)
                                    <div class="hidden @xl:block">Usage</div>
                                @endif
                                <x-table.sort-header label="Status" asc="status-asc" desc="status-desc"
                                    action="setServerSort" :current="$serverSort" />
                            </div>
                            @foreach ($dashboardServers as $server)
                                @php
                                    [$serverStatus, $serverStatusType] = $serverStatuses[$server->id];
                                @endphp
                                <a href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}" {{ wireNavigate() }}
                                    wire:key="dashboard-server-row-{{ $server->uuid }}"
                                    class="grid {{ $serverTableColumns }} gap-4 @2xl:min-w-[480px] hover:no-underline {{ $tableRow }}">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <div
                                            class="resource-glyph flex size-8 shrink-0 items-center justify-center rounded-xl border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/10 dark:bg-white/[0.04]">
                                            <x-reicon name="servers" class="size-4 text-purple-600 dark:text-purple-400" />
                                        </div>
                                        <p class="truncate text-[13px] font-semibold text-neutral-900 dark:text-fg">
                                            {{ $server->name }}
                                        </p>
                                    </div>
                                    <p class="hidden truncate text-neutral-500 @2xl:block dark:text-fg-dim text-xs">
                                        {{ filled($server->description) ? $server->description : '-' }}
                                    </p>
                                    @if ($showServerUsage)
                                        <div class="hidden @xl:block">
                                            @if ($server->isMetricsEnabled())
                                                <div class="flex flex-col gap-1 text-[11px] text-neutral-500 tabular-nums dark:text-fg-dim"
                                                    x-data="{
                                                        cpu: null,
                                                        memory: null,
                                                        stop: null,
                                                        init() {
                                                            const latest = points => points?.length ? points[points.length - 1][1] : null;
                                                            this.stop = Livewire.on(@js('dashboard-server-metrics-'.$server->uuid), data => {
                                                                this.cpu = latest(data[0]?.cpu);
                                                                this.memory = latest(data[0]?.memory);
                                                            });
                                                        },
                                                        destroy() {
                                                            this.stop?.();
                                                        },
                                                    }">
                                                    @foreach (['cpu' => ['CPU', 'bg-[rgb(30,144,255)]'], 'memory' => ['Mem', 'bg-[rgb(168,85,247)]']] as $metric => [$metricLabel, $metricColor])
                                                        <div class="flex items-center gap-2">
                                                            <span class="w-7 shrink-0 font-medium">{{ $metricLabel }}</span>
                                                            <span class="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-neutral-200 dark:bg-white/[0.08]">
                                                                <span class="block h-full rounded-full {{ $metricColor }} transition-[width] duration-500"
                                                                    :style="{ width: `${Math.min(100, {{ $metric }} ?? 0)}%` }"></span>
                                                            </span>
                                                            <span class="w-8 shrink-0 text-right font-mono"
                                                                x-text="{{ $metric }} === null ? '-' : `${Math.round({{ $metric }})}%`">-</span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @else
                                                <span class="text-neutral-500 dark:text-fg-dim">-</span>
                                            @endif
                                        </div>
                                    @endif
                                    <div class="flex items-center gap-2 text-[11.5px] font-medium text-neutral-600 dark:text-fg-dim">
                                        <span class="sc-pill @if($serverStatusType === 'success') bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20 @elseif($serverStatusType === 'warning') bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20 @else bg-red-500/10 text-red-700 dark:text-red-400 border border-red-500/20 @endif">
                                            <span class="sc-pulse-dot size-1.5 @if($serverStatusType === 'success') bg-emerald-500 @elseif($serverStatusType === 'warning') bg-amber-500 @else bg-red-500 @endif"></span>
                                            <span>{{ $serverStatus }}</span>
                                        </span>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
                @endif
            </div>
        </div>
    </div>
</div>
