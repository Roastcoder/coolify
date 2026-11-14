<div class="flex h-full min-h-0 flex-col">
    <x-slot:title>
        Code | StandaloneCoders Cloud
    </x-slot>
<script>
    window.scIde = function (config) {
        const MONACO = '/js/monaco-editor-0.52.2/min';
        let editor = null;
        let diffEditor = null;
        const models = {};

        return {
            config,
            pick: '', pickRoot: '', label: '', root: '',
            entries: {}, expanded: {},
            tabs: [], active: null, language: '', cursor: { line: 1, col: 1 },
            sidebar: window.innerWidth >= 1024, claude: false,
            ask: '', thinking: false, diff: false,
            busy: false, message: '',

            init() {
                this.loadMonaco();
            },

            say(text) { this.message = text; },

            fail(error) {
                this.say(error);
                window.Livewire?.dispatch?.('error', [error]);
                return null;
            },

            async call(method, ...args) {
                const result = await this.$wire.call(method, ...args);
                if (result && result.error) {
                    return this.fail(result.error);
                }
                return result ? result.data : null;
            },

            loadMonaco() {
                const ready = () => {
                    require.config({ paths: { vs: `${MONACO}/vs` } });
                    const proxy = URL.createObjectURL(new Blob([`self.MonacoEnvironment={baseUrl:'${location.origin}${MONACO}'};importScripts('${location.origin}${MONACO}/vs/base/worker/workerMain.js');`], { type: 'text/javascript' }));
                    window.MonacoEnvironment = { getWorkerUrl: () => proxy };
                    require(['vs/editor/editor.main'], () => this.createEditor());
                };
                if (typeof require !== 'undefined' && require.config) {
                    return ready();
                }
                const script = document.createElement('script');
                script.src = `${MONACO}/vs/loader.js`;
                script.onload = ready;
                document.head.appendChild(script);
            },

            theme() {
                return document.documentElement.classList.contains('dark') ? 'vs-dark' : 'vs';
            },

            createEditor() {
                const options = { automaticLayout: true, fontSize: 14, minimap: { enabled: window.innerWidth >= 1024 }, scrollBeyondLastLine: false, theme: this.theme(), tabSize: 4 };
                editor = monaco.editor.create(this.$refs.editor, { ...options, model: null });
                diffEditor = monaco.editor.createDiffEditor(this.$refs.diff, { ...options, renderSideBySide: window.innerWidth >= 900, originalEditable: false });
                editor.onDidChangeCursorPosition((e) => { this.cursor = { line: e.position.lineNumber, col: e.position.column }; });
                new MutationObserver(() => monaco.editor.setTheme(this.theme())).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
            },

            async open() {
                if (! this.pick || this.busy) return;
                if (this.tabs.some((tab) => tab.dirty) && ! confirm('Close the open files? Unsaved changes are lost.')) return;
                this.busy = true;
                this.say('Opening…');
                const result = await this.call('openWorkspace', this.pick, this.pickRoot);
                this.busy = false;
                if (! result) return;
                Object.values(models).forEach((model) => model.dispose());
                Object.keys(models).forEach((key) => delete models[key]);
                this.tabs = []; this.active = null; this.entries = {}; this.expanded = {};
                this.label = result.label; this.root = result.root; this.pickRoot = result.root;
                await this.load(this.root);
                this.expanded[this.root] = true;
                this.say('Ready');
            },

            async load(path) {
                const list = await this.call('listDir', path);
                if (list) this.entries[path] = list;
            },

            async toggle(path) {
                if (this.expanded[path]) { this.expanded[path] = false; return; }
                if (! this.entries[path]) await this.load(path);
                this.expanded[path] = true;
            },

            async refresh() {
                const open = Object.keys(this.expanded).filter((path) => this.expanded[path]);
                this.entries = {};
                for (const path of open) await this.load(path);
            },

            rows() {
                const rows = [];
                const walk = (path, depth) => {
                    for (const entry of this.entries[path] || []) {
                        rows.push({ ...entry, depth });
                        if (entry.type === 'dir' && this.expanded[entry.path]) walk(entry.path, depth + 1);
                    }
                };
                if (this.root) walk(this.root, 0);
                return rows;
            },

            async openFile(path) {
                if (window.innerWidth < 1024) this.sidebar = false;
                if (models[path]) return this.show(path);
                this.say(`Opening ${path}…`);
                const content = await this.call('readFile', path);
                if (content === null) return;
                const model = monaco.editor.createModel(content, undefined, monaco.Uri.file(path));
                models[path] = model;
                const tab = { path, name: path.split('/').pop(), dirty: false, saved: model.getAlternativeVersionId() };
                model.onDidChangeContent(() => { tab.dirty = model.getAlternativeVersionId() !== tab.saved; this.tabs = [...this.tabs]; });
                this.tabs.push(tab);
                this.show(path);
                this.say('');
            },

            show(path) {
                this.active = path;
                this.diff = false;
                editor.setModel(models[path]);
                this.language = models[path].getLanguageId();
                editor.focus();
            },

            close(path) {
                const tab = this.tabs.find((t) => t.path === path);
                if (tab?.dirty && ! confirm(`${tab.name} has unsaved changes. Close it anyway?`)) return;
                this.tabs = this.tabs.filter((t) => t.path !== path);
                models[path]?.dispose();
                delete models[path];
                if (this.active === path) {
                    const next = this.tabs[this.tabs.length - 1];
                    next ? this.show(next.path) : (this.active = null, editor.setModel(null));
                }
            },

            async save() {
                const tab = this.tabs.find((t) => t.path === this.active);
                if (! tab) return;
                const model = models[tab.path];
                this.say(`Saving ${tab.name}…`);
                if (await this.call('writeFile', tab.path, model.getValue()) === null) return;
                tab.saved = model.getAlternativeVersionId();
                tab.dirty = false;
                this.tabs = [...this.tabs];
                this.say(`Saved ${tab.name}`);
            },

            onKey(event) {
                if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
                    event.preventDefault();
                    this.save();
                }
            },

            parentOf(path) { return path.substring(0, path.lastIndexOf('/')) || '/'; },

            currentFolder() {
                if (! this.active) return this.root;
                return this.parentOf(this.active);
            },

            async create(folder) {
                const name = prompt(folder ? 'New folder name' : 'New file name');
                if (! name || name.includes('/')) return;
                const base = this.currentFolder();
                const path = `${base === '/' ? '' : base}/${name}`;
                if (await this.call('createEntry', path, folder) === null) return;
                await this.load(base);
                this.expanded[base] = true;
                if (! folder) this.openFile(path);
            },

            async rename(row) {
                const name = prompt('New name', row.name);
                if (! name || name === row.name || name.includes('/')) return;
                const parent = this.parentOf(row.path);
                if (await this.call('renameEntry', row.path, `${parent === '/' ? '' : parent}/${name}`) === null) return;
                if (models[row.path]) this.close(row.path);
                await this.load(parent);
            },

            async remove(row) {
                if (! confirm(`Delete ${row.path}? This cannot be undone.`)) return;
                if (await this.call('deleteEntry', row.path) === null) return;
                if (models[row.path]) { this.tabs.find((t) => t.path === row.path).dirty = false; this.close(row.path); }
                await this.load(this.parentOf(row.path));
            },

            async askClaude() {
                if (! this.active || ! this.ask.trim() || this.thinking) return;
                const path = this.active;
                const original = models[path].getValue();
                this.thinking = true;
                this.say('Claude is editing…');
                if (await this.call('askClaude', path, original, this.ask) === null) { this.thinking = false; return; }
                const poll = async () => {
                    const result = await this.$wire.call('claudeResult');
                    if (! result) return setTimeout(poll, 1500);
                    this.thinking = false;
                    if (result.error) return this.fail(result.error);
                    if (! models[path]) return this.say('The file was closed.');
                    this.show(path);
                    this.pendingPath = path;
                    diffEditor.setModel({ original: monaco.editor.createModel(original, models[path].getLanguageId()), modified: monaco.editor.createModel(result.content, models[path].getLanguageId()) });
                    this.diff = true;
                    this.say('Review Claude\'s changes, then Accept or Reject.');
                };
                setTimeout(poll, 1500);
            },

            acceptDiff() {
                const model = models[this.pendingPath];
                const changed = diffEditor.getModel().modified.getValue();
                if (model) {
                    model.pushEditOperations([], [{ range: model.getFullModelRange(), text: changed }], () => null);
                }
                this.closeDiff();
                this.ask = '';
                this.say('Changes applied. Save to write them to the server.');
            },

            rejectDiff() {
                this.closeDiff();
                this.say('Changes rejected.');
            },

            closeDiff() {
                const pair = diffEditor.getModel();
                diffEditor.setModel(null);
                pair?.original.dispose();
                pair?.modified.dispose();
                this.diff = false;
                if (this.pendingPath && models[this.pendingPath]) this.show(this.pendingPath);
            },
        };
    };
</script>

<div class="sc-ide flex h-full min-h-0 flex-col" x-data="scIde(@js(['configured' => $configured]))" x-on:keydown.window="onKey($event)">

    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-neutral-200 px-3 py-2 dark:border-white/[0.07]">
        <button type="button" class="sc-ide-icon lg:hidden" x-on:click="sidebar = ! sidebar" aria-label="Toggle explorer">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
        </button>
        <label for="sc-ide-workspace" class="sr-only">Workspace</label>
        <select id="sc-ide-workspace" x-model="pick" class="input h-9 w-full max-w-xs py-0 sm:w-72">
            <option value="">Choose a project or server…</option>
            @foreach ($workspaces as $group)
                <optgroup label="{{ $group['label'] }}">
                    @foreach ($group['items'] as $item)
                        <option value="{{ $item['key'] }}">{{ $item['kind'] }} · {{ $item['label'] }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        <label for="sc-ide-root" class="sr-only">Folder</label>
        <input id="sc-ide-root" x-model="pickRoot" placeholder="Folder (optional), e.g. /app" class="input h-9 w-full max-w-[14rem] py-0" x-on:keydown.enter="open()">
        <button type="button" class="button" x-on:click="open()" x-bind:disabled="! pick || busy">Open</button>
        <div class="ml-auto flex items-center gap-2">
            <button type="button" class="button" x-on:click="claude = ! claude" x-bind:aria-pressed="claude.toString()">Claude</button>
            <a href="{{ route('ai.index') }}" class="button" {{ wireNavigate() }}>Chat</a>
        </div>
    </div>

        <div class="relative flex min-h-0 flex-1">
            {{-- Explorer --}}
            <aside x-show="sidebar" x-cloak class="sc-ide-explorer absolute inset-y-0 left-0 z-20 flex w-72 flex-col border-r border-neutral-200 bg-white lg:static lg:z-auto lg:flex dark:border-white/[0.07] dark:bg-panel"
                aria-label="Explorer">
                <div class="flex items-center gap-1 border-b border-neutral-200 px-3 py-2 dark:border-white/[0.07]">
                    <span class="min-w-0 flex-1 truncate text-xs font-semibold uppercase tracking-wide" x-text="label || 'Explorer'"></span>
                    <button type="button" class="sc-ide-icon" title="New file" aria-label="New file" x-on:click="create(false)" x-bind:disabled="! root">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8zM14 3v5h5M12 12v6M9 15h6" /></svg>
                    </button>
                    <button type="button" class="sc-ide-icon" title="New folder" aria-label="New folder" x-on:click="create(true)" x-bind:disabled="! root">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2zM12 10v6M9 13h6" /></svg>
                    </button>
                    <button type="button" class="sc-ide-icon" title="Refresh" aria-label="Refresh" x-on:click="refresh()" x-bind:disabled="! root">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 4v6h6M20 20v-6h-6M5.5 15a7 7 0 0011.9 2.6L20 14M18.5 9A7 7 0 006.6 6.4L4 10" /></svg>
                    </button>
                </div>
                <div class="min-h-0 flex-1 overflow-auto py-1 text-[13px]" role="tree">
                    <template x-if="! root">
                        <p class="px-3 py-4 text-sm">Choose a project's app, database or service, or a server, then Open.</p>
                    </template>
                    <template x-for="row in rows()" :key="row.path">
                        <div class="sc-ide-row group" role="treeitem" x-bind:aria-expanded="row.type === 'dir' ? (!! expanded[row.path]).toString() : null"
                            x-bind:class="{ 'sc-ide-row-active': active === row.path }" x-bind:style="`padding-left: ${0.5 + row.depth * 0.85}rem`"
                            x-on:click="row.type === 'dir' ? toggle(row.path) : openFile(row.path)" tabindex="0" x-on:keydown.enter="row.type === 'dir' ? toggle(row.path) : openFile(row.path)">
                            <span class="w-3 shrink-0 text-center opacity-60" x-text="row.type === 'dir' ? (expanded[row.path] ? '▾' : '▸') : ''"></span>
                            <span class="shrink-0" x-bind:class="row.type === 'dir' ? 'text-coollabs dark:text-warning' : 'opacity-70'" x-text="row.type === 'dir' ? '▣' : '▤'" aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 truncate" x-text="row.name"></span>
                            <span class="hidden shrink-0 gap-1 group-hover:flex group-focus-within:flex">
                                <button type="button" class="sc-ide-mini" title="Rename" x-on:click.stop="rename(row)">✎</button>
                                <button type="button" class="sc-ide-mini" title="Delete" x-on:click.stop="remove(row)">✕</button>
                            </span>
                        </div>
                    </template>
                </div>
            </aside>

            {{-- Editor --}}
            <section class="flex min-w-0 flex-1 flex-col" aria-label="Editor">
                <div class="sc-ide-tabs flex min-h-9 overflow-x-auto border-b border-neutral-200 dark:border-white/[0.07]" role="tablist">
                    <template x-for="tab in tabs" :key="tab.path">
                        <div role="tab" x-bind:aria-selected="(active === tab.path).toString()" class="sc-ide-tab group" x-bind:class="{ 'sc-ide-tab-active': active === tab.path }"
                            x-on:click="show(tab.path)" x-on:mousedown.middle.prevent="close(tab.path)" x-bind:title="tab.path">
                            <span class="truncate" x-text="tab.name"></span>
                            <button type="button" class="sc-ide-close" x-on:click.stop="close(tab.path)" x-bind:aria-label="`Close ${tab.name}`">
                                <span x-show="tab.dirty" class="sc-ide-dot" aria-hidden="true"></span>
                                <span x-show="! tab.dirty">×</span>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="relative min-h-0 flex-1" wire:ignore>
                    <div x-ref="editor" class="absolute inset-0" x-show="active && ! diff"></div>
                    <div x-ref="diff" class="absolute inset-0" x-show="diff"></div>
                    <div x-show="! active && ! diff" class="absolute inset-0 flex items-center justify-center p-6 text-center text-sm">
                        <div>
                            <p class="text-base font-medium">StandaloneCoders Code</p>
                            <p class="mt-1">Open a file from the explorer. Ctrl/Cmd + S saves. Ask Claude to change the open file.</p>
                        </div>
                    </div>
                    <div x-show="diff" class="absolute right-3 top-3 z-10 flex gap-2">
                        <button type="button" class="button" x-on:click="acceptDiff()">Accept</button>
                        <button type="button" class="button" x-on:click="rejectDiff()">Reject</button>
                    </div>
                </div>
            </section>

            {{-- Claude --}}
            <aside x-show="claude" x-cloak class="absolute inset-y-0 right-0 z-20 flex w-80 flex-col gap-3 border-l border-neutral-200 bg-white p-3 lg:static lg:z-auto dark:border-white/[0.07] dark:bg-panel"
                aria-label="Claude">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold">Claude</h2>
                    <button type="button" class="sc-ide-icon" x-on:click="claude = false" aria-label="Close Claude">×</button>
                </div>
                @unless ($configured)
                    <p class="text-sm text-warning">Add a Claude API key in <a class="underline" href="{{ route('settings.ai') }}">Settings &gt; AI assistant</a>.</p>
                @endunless
                <p class="text-xs" x-text="active ? `Changes ${active}` : 'Open a file first.'"></p>
                <label for="sc-ide-ask" class="sr-only">What should Claude change?</label>
                <textarea id="sc-ide-ask" x-model="ask" rows="6" class="input resize-y" placeholder="e.g. Add gzip to this nginx config, or fix the failing healthcheck"
                    x-on:keydown.meta.enter="askClaude()" x-on:keydown.ctrl.enter="askClaude()"></textarea>
                <button type="button" class="button" x-on:click="askClaude()" x-bind:disabled="! active || ! ask.trim() || thinking || ! config.configured"
                    x-text="thinking ? 'Claude is editing…' : 'Ask Claude'"></button>
                <p class="text-xs">Claude returns the whole file. You see the changes side by side and choose Accept or Reject; nothing is saved until you save.</p>
            </aside>
        </div>

        {{-- Status bar --}}
        <footer class="sc-ide-status flex items-center gap-4 overflow-hidden px-3 text-xs" role="status">
            <span class="truncate" x-text="label ? `${label} · ${root}` : 'No workspace'"></span>
            <span class="ml-auto shrink-0" x-show="active" x-text="`Ln ${cursor.line}, Col ${cursor.col}`"></span>
            <span class="shrink-0" x-show="active" x-text="language"></span>
            <span class="shrink-0" x-text="message"></span>
        </footer>
</div>
</div>
