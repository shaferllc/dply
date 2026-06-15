@php
    $opsReady = $server->isReady() && $server->ssh_private_key;
    $promptUser = $server->ssh_user ?: 'root';
    $promptHost = $server->name ?: $server->ip_address;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="console"
    :title="__('Console')"
    :description="__('Quick read-only SSH console for inspecting the server. For saved scripts or longer jobs use Run.')"
    explainer-tone="warn"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-slot:explainer>
        <p>{{ __('A lightweight shell prompt for poking at the server: type a command, hit Enter, output appears below. History is kept per session so you can scroll back through recent runs.') }}</p>
        <p>{{ __('Each command runs as the dply SSH user with full shell access — same blast radius as the Run page. Output is captured up to 16KB; for streaming/long-running jobs use Run.') }}</p>
    </x-slot:explainer>

    @if ($opsReady)
        {{-- Kick the autocomplete-source SSH probes in the background after first
             paint. wire:init fires one extra request that populates binList +
             historyList without blocking initial render. The same call also
             probes for the dply CLI binary so the install banner below can
             render in the same round-trip. --}}
        <div @if (! $probesLoaded) wire:init="loadProbes" @endif class="hidden" aria-hidden="true"></div>

        {{-- dply CLI install banner. Renders three different shapes based on
             the composite probe in loadProbes():
               - missing: full install pitch
               - partial: repair affordance + list of missing pieces
               - ok: nothing here; the chrome bar shows the green pill instead
        --}}
        @if ($probesLoaded && $cliState === 'missing')
            <div class="mb-4 rounded-2xl border border-brand-sage/40 bg-brand-sage/10 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 max-w-3xl">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Install the dply CLI on this server') }}</p>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('Adds /usr/local/bin/dply — a small bash wrapper for status, restart, tail, site list, and recipe run. Reads /etc/dply/state.json that we push alongside the install. Auditable: `cat $(which dply)`.') }}
                        </p>
                        @if ($cliInstallError)
                            <p class="mt-2 text-xs text-rose-700">{{ $cliInstallError }}</p>
                        @endif
                    </div>
                    <button
                        type="button"
                        wire:click="installCli"
                        wire:loading.attr="disabled"
                        wire:target="installCli"
                        @disabled($cliInstalling)
                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 focus:outline-none focus:ring-2 focus:ring-brand-sage/40 disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="installCli">{{ __('Install dply CLI') }}</span>
                        <span wire:loading wire:target="installCli">{{ __('Installing…') }}</span>
                    </button>
                </div>
            </div>
        @elseif ($probesLoaded && $cliState === 'partial')
            <div class="mb-4 rounded-2xl border border-amber-300/70 bg-amber-50/70 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 max-w-3xl">
                        <p class="text-sm font-semibold text-amber-900">{{ __('dply CLI install is incomplete') }}</p>
                        <p class="mt-1 text-sm leading-6 text-amber-900/80">
                            {{ __('The binary is on the server but supporting pieces are missing. Repair re-runs the installer (idempotent).') }}
                        </p>
                        <ul class="mt-2 space-y-0.5 text-xs text-amber-900/90">
                            <li class="flex items-center gap-1.5">
                                <span class="inline-block h-1.5 w-1.5 rounded-full {{ $cliBinaryOk ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                                <span>/usr/local/bin/dply {{ $cliBinaryOk ? __('present') : __('missing') }}</span>
                            </li>
                            <li class="flex items-center gap-1.5">
                                <span class="inline-block h-1.5 w-1.5 rounded-full {{ $cliJqOk ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                                <span>jq {{ $cliJqOk ? __('installed') : __('missing — apt install jq') }}</span>
                            </li>
                            <li class="flex items-center gap-1.5">
                                <span class="inline-block h-1.5 w-1.5 rounded-full {{ $cliStateFileOk ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                                <span>/etc/dply/state.json {{ $cliStateFileOk ? __('readable') : __('missing or unreadable') }}</span>
                            </li>
                        </ul>
                        @if ($cliInstallError)
                            <p class="mt-2 text-xs text-rose-700">{{ $cliInstallError }}</p>
                        @endif
                    </div>
                    <button
                        type="button"
                        wire:click="installCli"
                        wire:loading.attr="disabled"
                        wire:target="installCli"
                        @disabled($cliInstalling)
                        class="inline-flex items-center gap-1.5 rounded-lg bg-amber-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-amber-700/90 focus:outline-none focus:ring-2 focus:ring-amber-500/40 disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="installCli">{{ __('Repair install') }}</span>
                        <span wire:loading wire:target="installCli">{{ __('Repairing…') }}</span>
                    </button>
                </div>
            </div>
        @endif

        {{-- The Alpine wrapper owns sidebar pinned-state (mirrored to localStorage so
             the preference survives reloads and tab switches) and the help-search
             text. The Livewire `$helpOpen` provides the initial value on first
             render only. --}}
        <div
            x-data="{
                open: (() => {
                    const stored = localStorage.getItem('dply.console.helpOpen');
                    return stored === null ? @js($helpOpen) : stored === '1';
                })(),
                q: '',
                toggle() {
                    this.open = !this.open;
                    localStorage.setItem('dply.console.helpOpen', this.open ? '1' : '0');
                },
                matches(text) {
                    const needle = this.q.trim().toLowerCase();
                    if (needle === '') return true;
                    return text.toLowerCase().includes(needle);
                },
            }"
            class="grid grid-cols-1 gap-4"
            :class="{ 'lg:grid-cols-[minmax(0,1fr)_320px]': open }"
        >
            {{-- Console card.

                 NOTE: no overflow-hidden — the autocomplete dropdown is
                 absolute-positioned above the prompt and must be allowed to
                 paint outside the card boundary. We round the top/bottom
                 inner elements themselves so the card corners stay clean. --}}
            <div class="dply-card p-0 min-w-0">
                {{-- Quick action chips + sidebar toggle --}}
                <div class="flex flex-wrap items-center gap-2 rounded-t-2xl border-b border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                    <span class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Quick') }}</span>
                    @foreach ($quickActions as $i => $action)
                        <button
                            type="button"
                            wire:click="runQuickAction({{ $i }})"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
                            title="{{ $action['cmd'] }}"
                        >
                            {{ $action['label'] }}
                        </button>
                    @endforeach
                    <div class="ml-auto flex items-center gap-2">
                        @if ($cliState === 'ok')
                            <span class="inline-flex items-center gap-1 rounded-full border border-brand-sage/40 bg-brand-sage/10 px-2 py-0.5 text-[10px] font-medium text-brand-ink" title="{{ __('Run `dply --help` on the box for subcommands.') }}">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                dply CLI{{ $cliVersion ? ' '.$cliVersion : '' }}
                            </span>
                        @elseif ($cliState === 'partial')
                            <span class="inline-flex items-center gap-1 rounded-full border border-amber-400/60 bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-900" title="{{ __('dply CLI install is incomplete — see banner above.') }}">
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                                dply CLI{{ $cliVersion ? ' '.$cliVersion : '' }} ({{ __('needs repair') }})
                            </span>
                        @endif
                        @if (! empty($history))
                            <span class="text-[11px] text-brand-moss">{{ trans_choice('{1} :count entry|[2,*] :count entries', count($history), ['count' => count($history)]) }}</span>
                            <button
                                type="button"
                                wire:click="clearHistory"
                                class="text-xs font-medium text-brand-moss hover:text-brand-ink underline-offset-2 hover:underline"
                            >
                                {{ __('Clear') }}
                            </button>
                        @endif
                        <button
                            type="button"
                            x-on:click="toggle()"
                            x-bind:aria-pressed="open ? 'true' : 'false'"
                            class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            :class="{ 'bg-brand-sage/15 border-brand-sage/40': open }"
                            title="{{ __('Toggle help sidebar') }}"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="10" cy="10" r="7.5"/>
                                <path d="M8 7.5a2 2 0 1 1 3 1.7c-.7.4-1 .8-1 1.5V11"/>
                                <circle cx="10" cy="13.5" r="0.5" fill="currentColor"/>
                            </svg>
                            <span x-text="open ? @js(__('Hide help')) : @js(__('Show help'))"></span>
                        </button>
                    </div>
                </div>

                {{-- Terminal-style scrollback --}}
                <x-console-terminal-shell
                    :prompt-user="$promptUser"
                    :prompt-host="$promptHost"
                    class="rounded-none border-0 shadow-none ring-0"
                    max-height="520px"
                >
                    <x-slot:toolbar>
                        <div class="flex items-center gap-1.5">
                            <span class="inline-flex h-2 w-2 rounded-full bg-red-400/80" aria-hidden="true"></span>
                            <span class="inline-flex h-2 w-2 rounded-full bg-amber-300/80" aria-hidden="true"></span>
                            <span class="inline-flex h-2 w-2 rounded-full bg-brand-sage/80" aria-hidden="true"></span>
                        </div>
                        <span class="font-mono text-[11px] font-medium text-brand-forest">{{ $promptUser.'@'.$promptHost }}</span>
                        <span class="inline-flex items-center gap-1 rounded-full border border-brand-sage/30 bg-brand-sage/10 px-2 py-0.5 text-[10px] font-semibold text-brand-forest">
                            <span class="h-1.5 w-1.5 rounded-full bg-brand-forest" aria-hidden="true"></span>
                            {{ __('Live') }}
                        </span>
                    </x-slot:toolbar>

                    <x-slot:body>
                        <div
                            x-data="{}"
                            x-init="$el.scrollTop = $el.scrollHeight"
                            x-on:scroll-console-bottom.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
                            class="space-y-3"
                        >
                            @if (empty($history))
                                <p class="text-slate-400 italic">{{ __('Type a command below or pick a quick action above. History will appear here.') }}</p>
                            @endif

                            @foreach ($history as $entry)
                                <div>
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        <span class="text-brand-sage">{{ $promptUser.'@'.$promptHost }}</span><span class="text-slate-500">:~$</span>
                                        <span class="break-all text-slate-100">{{ $entry['cmd'] }}</span>
                                    </div>
                                    @if ($entry['error'])
                                        <pre class="mt-1 whitespace-pre-wrap break-words text-rose-300">{{ $entry['error'] }}</pre>
                                    @else
                                        @if ($entry['out'] !== '')
                                            <pre class="mt-1 whitespace-pre-wrap break-words text-slate-200">{{ $entry['out'] }}</pre>
                                        @endif
                                        @if (! is_null($entry['exit']) && $entry['exit'] !== 0)
                                            <p class="mt-1 text-[11px] text-amber-300">{{ __('exit :code', ['code' => $entry['exit']]) }}</p>
                                        @endif
                                    @endif
                                </div>
                            @endforeach

                            <div wire:loading wire:target="run,runQuickAction" class="text-slate-400">
                                <span class="text-brand-sage">{{ $promptUser.'@'.$promptHost }}</span><span class="text-slate-500">:~$</span>
                                <span class="ml-1 inline-flex items-center gap-1.5 animate-pulse">
                                    <x-spinner variant="slate" size="sm" />
                                    {{ __('running…') }}
                                </span>
                            </div>
                        </div>
                    </x-slot:body>

                    <x-slot:footer>
                        {{-- Prompt with Tab-triggered autocomplete dropdown.

                             The Alpine component owns the dropdown UI; the three
                             sources (catalog/installed/history) and the argspec map
                             are passed in as JSON from PHP. Selection state lives in
                             JS only — Livewire just owns the final command string. --}}
                        <form
                            wire:submit.prevent="run"
                            x-data="dplyConsoleAutocomplete({
                                catalog: @js($catalogCommands),
                                argspecs: @js((object) $argspecs),
                            })"
                            x-on:autocomplete-pick.stop="pick($event.detail)"
                            @keydown.tab.prevent="openOrCycle()"
                            @keydown.escape="close()"
                            @keydown.arrow-down.prevent="acOpen && next()"
                            @keydown.arrow-up.prevent="acOpen && prev()"
                            class="relative"
                        >
                            @if ($error)
                                <div class="mb-2.5 rounded-lg border border-rose-200/20 bg-rose-500/10 px-3 py-2">
                                    <p class="text-[11px] leading-relaxed text-rose-200">{{ $error }}</p>
                                </div>
                            @endif
                            @if ($probeError)
                                <p class="mb-2 text-[11px] text-amber-300">{{ __('Autocomplete probes failed: :err', ['err' => $probeError]) }}</p>
                            @endif

                            {{-- Dropdown — positioned above the input. --}}
                            <div
                                x-show="acOpen"
                                x-cloak
                                x-on:click.outside="close()"
                                class="absolute inset-x-0 bottom-full z-20 mb-1 overflow-hidden rounded-lg border border-white/10 bg-[#121826] font-mono text-[12px] shadow-xl shadow-black/40 sm:text-[12.5px]"
                            >
                                <template x-for="(group, gi) in groups" :key="gi">
                                    <div class="border-b border-white/5 last:border-b-0">
                                        <div class="flex items-center justify-between px-3 py-1 text-[10px] uppercase tracking-wide text-slate-500">
                                            <span x-text="group.label"></span>
                                            <span x-show="group.label === 'Installed' && !probesLoaded" class="italic text-slate-500">{{ __('indexing…') }}</span>
                                        </div>
                                        <template x-if="group.items.length === 0">
                                            <div class="px-3 py-1.5 italic text-slate-500">{{ __('no matches') }}</div>
                                        </template>
                                        <template x-for="(item, ii) in group.items" :key="gi + '-' + ii">
                                            <button
                                                type="button"
                                                x-on:mousedown.prevent="pickAt(gi, ii)"
                                                x-on:mouseenter="selected = flatIndex(gi, ii)"
                                                :class="selected === flatIndex(gi, ii) ? 'bg-brand-sage/20 text-slate-50' : 'text-slate-200 hover:bg-white/5'"
                                                class="block w-full px-3 py-1.5 text-left"
                                            >
                                                <span x-text="item"></span>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                                <div class="bg-white/5 px-3 py-1 text-[10px] text-slate-500">
                                    <kbd class="rounded bg-white/10 px-1">Tab</kbd> next
                                    · <kbd class="rounded bg-white/10 px-1">↑↓</kbd> select
                                    · <kbd class="rounded bg-white/10 px-1">Enter</kbd> insert
                                    · <kbd class="rounded bg-white/10 px-1">Esc</kbd> dismiss
                                </div>
                            </div>

                            <div class="flex items-center gap-2 font-mono text-[12px] sm:text-[12.5px]">
                                <span class="hidden shrink-0 text-brand-sage sm:inline">{{ $promptUser.'@'.$promptHost }}</span>
                                <span class="shrink-0 text-slate-500">:~$</span>
                                <input
                                    type="text"
                                    wire:model="command"
                                    x-ref="prompt"
                                    x-on:input="acOpen && refreshGroups()"
                                    x-on:keydown.enter.prevent="onEnter($event)"
                                    autocomplete="off"
                                    autocorrect="off"
                                    spellcheck="false"
                                    placeholder="{{ __('type a command, Tab for suggestions, Enter to run') }}"
                                    class="min-w-0 flex-1 rounded-md border border-white/10 bg-white/5 px-2.5 py-1.5 text-slate-100 placeholder-slate-500 caret-brand-sage focus:border-brand-sage/40 focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand-sage/20 disabled:cursor-not-allowed disabled:opacity-50"
                                    wire:loading.attr="disabled"
                                    wire:target="run,runQuickAction"
                                />
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="run,runQuickAction"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-brand-cream shadow-sm transition hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    <span wire:loading.remove wire:target="run,runQuickAction">{{ __('Run') }}</span>
                                    <span wire:loading wire:target="run,runQuickAction" class="inline-flex items-center gap-1.5">
                                        <x-spinner variant="cream" size="sm" />
                                        {{ __('Running') }}
                                    </span>
                                </button>
                            </div>
                            @error('command')
                                <p class="mt-1.5 text-[11px] text-rose-300">{{ $message }}</p>
                            @enderror
                        </form>
                    </x-slot:footer>
                </x-console-terminal-shell>
            </div>

            {{-- Help sidebar --}}
            <aside
                x-show="open"
                x-cloak
                class="dply-card overflow-hidden p-0 self-start sticky top-4 max-h-[calc(100vh-5rem)] flex flex-col"
            >
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Commands') }}</h3>
                        <button
                            type="button"
                            x-on:click="toggle()"
                            class="rounded p-1 text-brand-moss hover:bg-white/60 hover:text-brand-ink"
                            title="{{ __('Hide help') }}"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M5 5l10 10"/><path d="M15 5L5 15"/>
                            </svg>
                        </button>
                    </div>
                    <input
                        type="text"
                        x-model="q"
                        placeholder="{{ __('Search…') }}"
                        class="mt-2 w-full rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-sm text-brand-ink placeholder-brand-moss/70 focus:border-brand-sage focus:outline-none focus:ring-1 focus:ring-brand-sage/40"
                    />
                    <p class="mt-1 text-[11px] text-brand-moss">{{ __('Click to insert. Press Enter in the prompt to run.') }}</p>
                </div>

                <div class="overflow-y-auto flex-1 px-3 py-3 space-y-4">
                    @forelse ($catalogSections as $section)
                        <section x-show="matches(@js($section['haystack']))">
                            <header class="mb-1">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ $section['label'] }}</h4>
                                @if (! empty($section['description']))
                                    <p class="text-[11px] text-brand-moss/80">{{ $section['description'] }}</p>
                                @endif
                            </header>
                            <ul class="space-y-1">
                                @foreach ($section['entries'] as $entry)
                                    <li x-show="matches(@js($entry['haystack']))">
                                        <button
                                            type="button"
                                            wire:click="insertCommand({{ \Illuminate\Support\Js::from($entry['command']) }})"
                                            class="group w-full rounded-md border border-transparent px-2 py-1.5 text-left hover:border-brand-ink/15 hover:bg-brand-sand/30"
                                        >
                                            <code class="block font-mono text-[12px] text-brand-ink break-all">{{ $entry['command'] }}</code>
                                            @if (! empty($entry['description']))
                                                <span class="mt-0.5 block text-[11px] text-brand-moss leading-snug">{{ $entry['description'] }}</span>
                                            @endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @empty
                        <p class="text-xs italic text-brand-moss">{{ __('No catalog entries match this server yet.') }}</p>
                    @endforelse
                </div>
            </aside>
        </div>

        <p class="mt-3 text-xs text-brand-moss">
            {{ __('Need to save and re-run? Promote a command into a saved recipe on the') }}
            <a href="{{ route('servers.run', $server) }}" wire:navigate class="font-medium text-brand-ink underline-offset-2 hover:underline">{{ __('Run page') }}</a>.
        </p>
    @else
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <x-icon-badge tone="amber">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Waiting on provisioning') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Provisioning and SSH must be ready before you can use the console.') }}</p>
                    </div>
            </div>
        </section>
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>

    @push('scripts')
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('dplyConsoleAutocomplete', (config) => ({
                    catalog: config.catalog || [],
                    argspecs: config.argspecs || {},

                    acOpen: false,
                    groups: [],
                    selected: 0,

                    // Live values come from Livewire so probe updates show up
                    // immediately after wire:init populates them.
                    get bins() { return this.$wire.binList || []; },
                    get history() { return this.$wire.historyList || []; },
                    get probesLoaded() { return !!this.$wire.probesLoaded; },

                    openOrCycle() {
                        if (this.acOpen) {
                            this.next();
                        } else {
                            this.refreshGroups();
                            if (this.totalItems() > 0) {
                                this.acOpen = true;
                                this.selected = 0;
                            }
                        }
                    },

                    close() {
                        this.acOpen = false;
                        this.selected = 0;
                    },

                    onEnter(ev) {
                        if (this.acOpen && this.totalItems() > 0) {
                            ev.preventDefault();
                            this.pickSelected();
                            return;
                        }
                        // Dropdown closed → let the form submit normally.
                        ev.target.form && ev.target.form.requestSubmit();
                    },

                    next() {
                        const n = this.totalItems();
                        if (!n) return;
                        this.selected = (this.selected + 1) % n;
                    },

                    prev() {
                        const n = this.totalItems();
                        if (!n) return;
                        this.selected = (this.selected - 1 + n) % n;
                    },

                    totalItems() {
                        return this.groups.reduce((acc, g) => acc + g.items.length, 0);
                    },

                    flatIndex(gi, ii) {
                        let n = 0;
                        for (let i = 0; i < gi; i++) n += this.groups[i].items.length;
                        return n + ii;
                    },

                    pickAt(gi, ii) {
                        const group = this.groups[gi];
                        const value = group.items[ii];
                        if (value === undefined) return;
                        this.applyPick(value, group);
                    },

                    pickSelected() {
                        let remaining = this.selected;
                        for (const group of this.groups) {
                            if (remaining < group.items.length) {
                                this.applyPick(group.items[remaining], group);
                                return;
                            }
                            remaining -= group.items.length;
                        }
                    },

                    applyPick(value, group) {
                        const current = this.$wire.command || '';
                        let next;
                        if (group.tokenReplace) {
                            // Replace the trailing token (argspec mode).
                            const m = current.match(/(.*\s)(\S*)$/);
                            next = (m ? m[1] : '') + value;
                        } else {
                            next = value;
                        }
                        this.$wire.command = next;
                        this.close();
                        this.$nextTick(() => {
                            const el = this.$refs.prompt;
                            if (el) {
                                el.focus();
                                el.setSelectionRange(next.length, next.length);
                            }
                        });
                    },

                    refreshGroups() {
                        const val = this.$wire.command || '';
                        const tokens = val.split(/\s+/);
                        const first = tokens[0] || '';
                        const spec = this.argspecs[first];

                        // Argspec mode: first token has a spec AND we have at least
                        // one space after it. The "current" token is the last one.
                        if (spec && tokens.length > 1) {
                            const tokenIdx = tokens.length - 1;
                            const cur = tokens[tokenIdx];
                            const prev = tokens[tokenIdx - 1];
                            let cands = [];
                            if (spec.positional && spec.positional[tokenIdx]) {
                                cands = cands.concat(spec.positional[tokenIdx]);
                            }
                            if (spec.after_flag && spec.after_flag[prev]) {
                                cands = cands.concat(spec.after_flag[prev]);
                            }
                            this.groups = [{
                                label: 'Arguments',
                                tokenReplace: true,
                                items: this.filterPrefix(cands, cur).slice(0, 10),
                            }];
                            return;
                        }

                        // Default mode: prefix-match against full input.
                        const prefix = val;
                        this.groups = [
                            { label: 'Catalog',   tokenReplace: false, items: this.filterPrefix(this.catalog, prefix).slice(0, 5) },
                            { label: 'Installed', tokenReplace: false, items: this.filterPrefix(this.bins, prefix).slice(0, 5) },
                            { label: 'History',   tokenReplace: false, items: this.filterPrefix(this.history, prefix).slice(0, 5) },
                        ];
                    },

                    filterPrefix(list, prefix) {
                        const p = (prefix || '').toLowerCase();
                        if (!p) return Array.from(new Set(list)).slice(0, 50);
                        const out = [];
                        const seen = new Set();
                        for (const s of list) {
                            if (s.toLowerCase().startsWith(p) && !seen.has(s)) {
                                seen.add(s);
                                out.push(s);
                            }
                        }
                        return out;
                    },
                }));
            });
        </script>
    @endpush
</x-server-workspace-layout>
