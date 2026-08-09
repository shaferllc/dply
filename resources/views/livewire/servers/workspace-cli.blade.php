@php
    $installUrl = route('cli.install');
    $sitesCount = $server->cachedSitesCount();
    $sshReady = $server->isReady();
    $statusLabel = $sshReady ? __('SSH ready') : __('Provisioning');
    $serverFlag = '--server '.$server->id;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="cli"
    :title="__('CLI')"
    :description="__('Install the dply CLI and drive this server from your terminal.')"
    hide-hero
>
    @include('livewire.servers.partials.workspace-flashes')

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-command-line"
            :title="__('CLI')"
            :note="__('One-time `dply login`, then the same operations as this workspace — scoped to your org.')"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                <x-docs-link
                    slug="account-cli"
                    class="!h-6 !gap-1 !rounded-md !px-2 !py-0 !text-xs !font-semibold"
                >
                    <x-heroicon-o-book-open class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Docs') }}
                </x-docs-link>
                <a
                    href="{{ route('profile.cli') }}"
                    wire:navigate
                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    {{ __('Sessions') }}
                    <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                </a>
            </x-slot:actions>
        </x-workspace-panel-head>

        {{-- Context strip --}}
        <dl class="grid grid-cols-2 gap-px border-b border-brand-ink/10 bg-brand-ink/5 sm:grid-cols-4" aria-label="{{ __('Server CLI context') }}">
            <div class="bg-white px-3 py-2">
                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Server') }}</dt>
                <dd class="mt-0.5 truncate text-sm font-semibold text-brand-ink" title="{{ $server->name }}">{{ $server->name }}</dd>
            </div>
            <div class="bg-white px-3 py-2">
                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Sites') }}</dt>
                <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $sitesCount }}</dd>
            </div>
            <div class="bg-white px-3 py-2">
                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                <dd class="mt-0.5 flex items-center gap-1.5 text-sm font-semibold {{ $sshReady ? 'text-brand-forest' : 'text-amber-800' }}">
                    <span @class([
                        'inline-flex h-1.5 w-1.5 rounded-full',
                        'bg-brand-sage' => $sshReady,
                        'bg-amber-500' => ! $sshReady,
                    ]) aria-hidden="true"></span>
                    {{ $statusLabel }}
                </dd>
            </div>
            <div class="bg-white px-3 py-2">
                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Server id') }}</dt>
                <dd class="mt-0.5 truncate font-mono text-xs text-brand-moss" title="{{ $server->id }}">{{ $server->id }}</dd>
            </div>
        </dl>

        {{-- Terminal mock + quick path --}}
        <div class="border-b border-brand-ink/10 lg:grid lg:grid-cols-5">
            <div class="relative overflow-hidden bg-[#0b1020] px-4 py-4 sm:px-5 lg:col-span-3">
                <div class="pointer-events-none absolute -end-16 -top-20 h-56 w-56 rounded-full bg-emerald-500/10 blur-3xl" aria-hidden="true"></div>
                <div class="pointer-events-none absolute -bottom-24 start-8 h-48 w-48 rounded-full bg-brand-gold/10 blur-3xl" aria-hidden="true"></div>

                <div class="relative flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <x-mac-window-dots />
                        <span class="font-mono text-2xs text-slate-500">dply · {{ $server->name }}</span>
                    </div>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.14em] text-emerald-200/90">
                        <span class="relative flex h-1.5 w-1.5">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/60 opacity-75"></span>
                            <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                        </span>
                        {{ __('Live') }}
                    </span>
                </div>

                <div class="relative mt-4 space-y-3 font-mono text-xs leading-relaxed sm:text-xs" x-data>
                    <p class="text-slate-500">{{ __('# From your laptop — not SSH into the VM') }}</p>

                    <div x-data="{ copied: false }" class="group">
                        <p class="text-emerald-300">
                            <span class="text-slate-400">you@laptop</span>
                            <span class="text-slate-300"> ~ $</span>
                            <span class="text-slate-100"> curl -fsSL {{ $installUrl }} | bash -s -- --login</span>
                            <button
                                type="button"
                                class="ms-1 inline-flex align-middle rounded p-0.5 text-slate-500 opacity-0 transition group-hover:opacity-100 hover:bg-white/10 hover:text-slate-200"
                                title="{{ __('Copy') }}"
                                aria-label="{{ __('Copy install command') }}"
                                @click="navigator.clipboard.writeText(@js('curl -fsSL '.$installUrl.' | bash -s -- --login')); copied = true; setTimeout(() => copied = false, 1500)"
                            >
                                <x-heroicon-o-clipboard class="h-3 w-3" />
                            </button>
                            <span x-show="copied" x-cloak class="ms-1 font-sans text-2xs font-medium text-emerald-400">{{ __('Copied') }}</span>
                        </p>
                        <p class="mt-1 text-slate-400">{{ __('Logged in. Type `dply` for interactive mode.') }}</p>
                    </div>

                    <div x-data="{ copied: false }" class="group">
                        <p class="text-emerald-300">
                            <span class="text-slate-400">you@laptop</span>
                            <span class="text-slate-300"> ~ $</span>
                            <span class="text-slate-100"> dply server show {{ $serverFlag }}</span>
                            <button
                                type="button"
                                class="ms-1 inline-flex align-middle rounded p-0.5 text-slate-500 opacity-0 transition group-hover:opacity-100 hover:bg-white/10 hover:text-slate-200"
                                title="{{ __('Copy') }}"
                                aria-label="{{ __('Copy show command') }}"
                                @click="navigator.clipboard.writeText(@js('dply server show '.$serverFlag)); copied = true; setTimeout(() => copied = false, 1500)"
                            >
                                <x-heroicon-o-clipboard class="h-3 w-3" />
                            </button>
                            <span x-show="copied" x-cloak class="ms-1 font-sans text-2xs font-medium text-emerald-400">{{ __('Copied') }}</span>
                        </p>
                        <p class="mt-1 text-slate-500">{{ __('Server: :name · :sites sites · :status', [
                            'name' => $server->name,
                            'sites' => (string) $sitesCount,
                            'status' => $statusLabel,
                        ]) }}</p>
                    </div>

                    <div x-data="{ copied: false }" class="group">
                        <p class="text-emerald-300">
                            <span class="text-slate-400">you@laptop</span>
                            <span class="text-slate-300"> ~ $</span>
                            <span class="text-slate-100"> dply server run {{ $serverFlag }} uptime</span>
                            <button
                                type="button"
                                class="ms-1 inline-flex align-middle rounded p-0.5 text-slate-500 opacity-0 transition group-hover:opacity-100 hover:bg-white/10 hover:text-slate-200"
                                title="{{ __('Copy') }}"
                                aria-label="{{ __('Copy run command') }}"
                                @click="navigator.clipboard.writeText(@js('dply server run '.$serverFlag.' uptime')); copied = true; setTimeout(() => copied = false, 1500)"
                            >
                                <x-heroicon-o-clipboard class="h-3 w-3" />
                            </button>
                            <span x-show="copied" x-cloak class="ms-1 font-sans text-2xs font-medium text-emerald-400">{{ __('Copied') }}</span>
                        </p>
                        <p class="mt-1 text-slate-500">
                            <span class="text-emerald-400/80">14:32:01 up 12 days,</span>
                            <span class="inline-block h-3.5 w-1.5 animate-pulse bg-emerald-300/90 align-middle" aria-hidden="true"></span>
                        </p>
                    </div>
                </div>
            </div>

            <div class="border-t border-brand-ink/10 bg-gradient-to-b from-brand-sand/25 to-white px-4 py-4 sm:px-5 lg:col-span-2 lg:border-t-0 lg:border-s">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Get started') }}</p>
                <ol class="mt-3 space-y-3">
                    <li class="flex gap-2.5">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-brand-ink text-xs font-semibold text-brand-cream">1</span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Install') }}</p>
                            <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Node 18+. Package served from this dply at `/cli/dply-cli.tgz`.') }}</p>
                        </div>
                    </li>
                    <li class="flex gap-2.5">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-brand-ink text-xs font-semibold text-brand-cream">2</span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Approve in browser') }}</p>
                            <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('`dply login` opens device flow — pick org + scopes, no API key paste.') }}</p>
                        </div>
                    </li>
                    <li class="flex gap-2.5">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-brand-ink text-xs font-semibold text-brand-cream">3</span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Drive this server') }}</p>
                            <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Commands below already include this server’s id. Bare `dply` opens interactive browse.') }}</p>
                        </div>
                    </li>
                </ol>

                <div class="mt-4 flex flex-wrap gap-1.5">
                    <a
                        href="{{ route('profile.cli') }}"
                        wire:navigate
                        class="inline-flex h-7 items-center gap-1 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest"
                    >
                        {{ __('Install & sessions') }}
                        <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                    </a>
                    <button
                        type="button"
                        x-data="{ copied: false }"
                        @click="navigator.clipboard.writeText(@js('curl -fsSL '.$installUrl.' | bash -s -- --login')); copied = true; setTimeout(() => copied = false, 1500)"
                        class="inline-flex h-7 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-clipboard class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span x-text="copied ? @js(__('Copied')) : @js(__('Copy install'))"></span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Capability tiles --}}
        <ul class="grid gap-px border-b border-brand-ink/10 bg-brand-ink/5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['icon' => 'bolt', 'title' => __('Device-flow login'), 'body' => __('Browser approval binds a CLI session to your user + org.')],
                ['icon' => 'server-stack', 'title' => __('Server-scoped'), 'body' => __('`--server` is pre-filled below so you never hunt for the ULID.')],
                ['icon' => 'sparkles', 'title' => __('Interactive shell'), 'body' => __('Bare `dply` / `menu` — numbers or typed shortcuts, autocomplete.')],
                ['icon' => 'shield-check', 'title' => __('Scoped tokens'), 'body' => __('Same abilities as API keys. Refresh scopes with `dply auth refresh`.')],
            ] as $feature)
                <li class="flex gap-2.5 bg-white px-3 py-3">
                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/8">
                        @switch($feature['icon'])
                            @case('bolt')
                                <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                                @break
                            @case('server-stack')
                                <x-heroicon-o-server-stack class="h-4 w-4" aria-hidden="true" />
                                @break
                            @case('sparkles')
                                <x-heroicon-o-sparkles class="h-4 w-4" aria-hidden="true" />
                                @break
                            @default
                                <x-heroicon-o-shield-check class="h-4 w-4" aria-hidden="true" />
                        @endswitch
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-brand-ink">{{ $feature['title'] }}</span>
                        <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ $feature['body'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>

        {{-- Full searchable command index --}}
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-queue-list"
            :title="__('Command index')"
            :note="__(':n commands · search or filter by family · server-scoped lines include this host’s id · press / to focus search', ['n' => $cliTotal])"
            :count="(string) $cliTotal"
        />

        <x-cli-command-index
            :groups="$cliGroups"
            :entries="$cliEntries"
            :total="$cliTotal"
            emphasize-server
        />

        <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
            <p class="text-xs leading-relaxed text-brand-moss">
                {{ __('Add `--json` to read commands to pipe them. Mutations queue over SSH like the workspace. Terminal help: `dply help` · `dply ls` · `dply ls server`. Revoke sessions under Profile → CLI.') }}
                <span class="mx-1 text-brand-mist/50" aria-hidden="true">·</span>
                <span class="font-mono text-brand-mist">{{ $server->id }}</span>
            </p>
        </div>
    </section>
</x-server-workspace-layout>
