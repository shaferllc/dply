@props([
    'compact' => false,
    'server' => null,
])

@php
    $sshUser = $server ? (trim((string) ($server->ssh_user ?? '')) !== '' ? trim((string) $server->ssh_user) : 'deploy') : 'deploy';
    $hostLabel = $server?->name ?: ($server?->ip_address ?: 'your-server');
    $prompt = $sshUser.'@'.$hostLabel;
@endphp

<div
    @class([
        'relative overflow-hidden',
        'rounded-2xl border border-brand-ink/10 bg-white shadow-sm ring-1 ring-brand-ink/[0.04]' => ! $compact,
        'rounded-xl' => $compact,
    ])
>
    {{-- Terminal hero --}}
    <div @class([
        'relative overflow-hidden bg-[#0b1020]',
        'px-5 pb-6 pt-5 sm:px-6 sm:pb-7 sm:pt-6' => ! $compact,
        'px-4 pb-4 pt-3.5' => $compact,
    ])>
        <div class="pointer-events-none absolute -end-16 -top-20 h-56 w-56 rounded-full bg-emerald-500/10 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-24 start-8 h-48 w-48 rounded-full bg-brand-gold/10 blur-3xl" aria-hidden="true"></div>

        <div class="relative flex items-start justify-between gap-3">
            <div class="flex items-center gap-2">
                <x-mac-window-dots />
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-emerald-200/90">
                <span class="relative flex h-1.5 w-1.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/60 opacity-75"></span>
                    <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                </span>
                {{ __('Coming soon') }}
            </span>
        </div>

        <div class="relative mt-4 font-mono text-[11px] leading-relaxed sm:text-xs">
            <p class="text-slate-500">{{ __('# Browser console preview') }}</p>
            <p class="mt-3 text-emerald-300">
                <span class="text-slate-400">{{ $prompt }}</span>
                <span class="text-slate-300"> ~ $</span>
                <span class="text-slate-100"> uptime</span>
            </p>
            <p class="mt-1 text-slate-400">{{ __(' 14:28:01 up 42 days,  3 users,  load average: 0.08, 0.12, 0.09') }}</p>
            <p class="mt-3 text-emerald-300">
                <span class="text-slate-400">{{ $prompt }}</span>
                <span class="text-slate-300"> ~ $</span>
                <span class="text-slate-100"> df -h /</span>
            </p>
            <p class="mt-1 text-slate-500">{{ __('Filesystem      Size  Used Avail Use%') }}</p>
            <p class="text-slate-400">{{ __('/dev/vda1        80G   22G   55G  29%') }}</p>
            <p class="mt-3 text-emerald-300">
                <span class="text-slate-400">{{ $prompt }}</span>
                <span class="text-slate-300"> ~ $</span>
                <span class="inline-block h-4 w-2 animate-pulse bg-emerald-300/90 align-middle" aria-hidden="true"></span>
            </p>
        </div>

        @if ($server)
            <div class="relative mt-4 inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-[11px] text-slate-300">
                <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0 text-emerald-300/80" aria-hidden="true" />
                <span>{{ __('Console will live on :server when it ships.', ['server' => $server->name]) }}</span>
            </div>
        @endif
    </div>

    {{-- Content --}}
    <div @class([
        'relative bg-gradient-to-b from-brand-cream to-white',
        'px-6 py-7 sm:px-8 sm:py-8' => ! $compact,
        'px-4 py-5' => $compact,
    ])>
        <div @class([
            'flex flex-col gap-6 sm:flex-row sm:items-start sm:justify-between' => ! $compact,
            'gap-4' => $compact,
        ])>
            <div @class(['max-w-md', 'text-center sm:text-left' => ! $compact])>
                <div @class([
                    'inline-flex items-center justify-center rounded-xl bg-brand-sage/10 text-brand-forest ring-1 ring-brand-sage/20',
                    'h-11 w-11' => ! $compact,
                    'h-9 w-9' => $compact,
                ])>
                    <x-heroicon-o-command-line @class([
                        'shrink-0',
                        'h-6 w-6' => ! $compact,
                        'h-5 w-5' => $compact,
                    ]) aria-hidden="true" />
                </div>
                <h2 @class([
                    'font-semibold tracking-tight text-brand-ink',
                    'mt-4 text-xl sm:text-2xl' => ! $compact,
                    'mt-3 text-base' => $compact,
                ])>{{ __('Browser console') }}</h2>
                <p @class([
                    'leading-6 text-brand-moss',
                    'mt-2 text-sm sm:text-[15px]' => ! $compact,
                    'mt-1.5 text-xs' => $compact,
                ])>
                    {{ __('Run shell commands from Dply — floating drawer on any page, full workspace tab per server, rolling history, and audited sessions.') }}
                </p>
            </div>

            @unless ($compact)
                <div class="hidden shrink-0 sm:block">
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-4 py-3 text-left shadow-sm">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Shortcut') }}</p>
                        <p class="mt-1 font-mono text-sm text-brand-ink">
                            <kbd class="rounded-md border border-brand-ink/15 bg-brand-sand/30 px-1.5 py-0.5 text-xs">`</kbd>
                            <span class="ms-1 text-brand-moss">{{ __('toggle drawer') }}</span>
                        </p>
                    </div>
                </div>
            @endunless
        </div>

        <ul @class([
            'grid gap-3',
            'mt-7 sm:grid-cols-2' => ! $compact,
            'mt-4' => $compact,
        ])>
            @foreach ([
                ['icon' => 'bolt', 'title' => __('Instant inspection'), 'body' => __('Run uptime, df, and logs without SSH client setup.')],
                ['icon' => 'rectangle-stack', 'title' => __('Global drawer'), 'body' => __('Pop open a terminal from any page in your org.')],
                ['icon' => 'sparkles', 'title' => __('Workspace tab'), 'body' => __('Full console per server with autocomplete and command help.')],
                ['icon' => 'shield-check', 'title' => __('Deployer-safe'), 'body' => __('RBAC-aware paths — owners run freely, deployers stay read-only.')],
            ] as $feature)
                <li @class([
                    'flex gap-3 rounded-xl border border-brand-ink/8 bg-white/90 p-3.5 shadow-sm ring-1 ring-brand-ink/[0.03]',
                    'sm:p-4' => ! $compact,
                ])>
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sand/35 text-brand-forest ring-1 ring-brand-ink/8">
                        @switch($feature['icon'])
                            @case('bolt')
                                <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                                @break
                            @case('rectangle-stack')
                                <x-heroicon-o-rectangle-stack class="h-4 w-4" aria-hidden="true" />
                                @break
                            @case('sparkles')
                                <x-heroicon-o-sparkles class="h-4 w-4" aria-hidden="true" />
                                @break
                            @default
                                <x-heroicon-o-shield-check class="h-4 w-4" aria-hidden="true" />
                        @endswitch
                    </span>
                    <span class="min-w-0 text-left">
                        <span @class([
                            'block font-semibold text-brand-ink',
                            'text-sm' => $compact,
                        ])>{{ $feature['title'] }}</span>
                        <span @class([
                            'mt-0.5 block leading-5 text-brand-moss',
                            'text-xs' => $compact,
                            'text-[13px]' => ! $compact,
                        ])>{{ $feature['body'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>

        <div @class([
            'flex flex-col gap-3 border-t border-brand-ink/8 sm:flex-row sm:items-center sm:justify-between',
            'mt-7 pt-5' => ! $compact,
            'mt-5 pt-4' => $compact,
        ])>
            <p @class([
                'text-brand-moss',
                'text-sm' => ! $compact,
                'text-xs' => $compact,
            ])>
                {{ __('We will enable this for your org when browser console ships.') }}
            </p>
            <span @class([
                'inline-flex items-center justify-center gap-1.5 rounded-full bg-brand-ink/[0.04] font-medium text-brand-mist',
                'px-3 py-1.5 text-xs' => ! $compact,
                'px-2.5 py-1 text-[10px]' => $compact,
            ])>
                <x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('In development') }}
            </span>
        </div>
    </div>
</div>
