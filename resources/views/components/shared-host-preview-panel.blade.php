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
    <div @class([
        'relative overflow-hidden bg-[#0b1020]',
        'px-5 pb-6 pt-5 sm:px-6 sm:pb-7 sm:pt-6' => ! $compact,
        'px-4 pb-4 pt-3.5' => $compact,
    ])>
        <div class="pointer-events-none absolute -end-16 -top-20 h-56 w-56 rounded-full bg-sky-500/10 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-24 start-8 h-48 w-48 rounded-full bg-brand-gold/10 blur-3xl" aria-hidden="true"></div>

        <div class="relative flex items-start justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-red-400/80" aria-hidden="true"></span>
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-amber-300/80" aria-hidden="true"></span>
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-400/80" aria-hidden="true"></span>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-sky-200/90">
                <span class="relative flex h-1.5 w-1.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-sky-400/60 opacity-75"></span>
                    <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-sky-400"></span>
                </span>
                {{ __('Coming soon') }}
            </span>
        </div>

        <div class="relative mt-4 font-mono text-[11px] leading-relaxed sm:text-xs">
            <p class="text-slate-500">{{ __('# Shared Host Radar — multi-site fairness') }}</p>
            <p class="mt-3 text-sky-300">
                <span class="text-slate-400">{{ $prompt }}</span>
                <span class="text-slate-300"> ~ $</span>
                <span class="text-slate-100"> dply shared-host scan</span>
            </p>
            <p class="mt-1 text-slate-400">{{ __('api.example.com   CPU 62%  ·  Mem 840 MB  ·  share 71%') }}</p>
            <p class="text-slate-400">{{ __('shop.example.com  CPU 18%  ·  Mem 210 MB  ·  share 21%') }}</p>
            <p class="text-amber-300/90">{{ __('shared redis:6379 → 2 sites  ⚠ restart impact') }}</p>
            <p class="text-slate-400">{{ __('contention: api deploy raised CPU to 94% for 12m') }}</p>
            <p class="mt-3 text-sky-300">
                <span class="text-slate-400">{{ $prompt }}</span>
                <span class="text-slate-300"> ~ $</span>
                <span class="inline-block h-4 w-2 animate-pulse bg-sky-300/90 align-middle" aria-hidden="true"></span>
            </p>
        </div>

        @if ($server)
            <div class="relative mt-4 inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-[11px] text-slate-300">
                <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0 text-sky-300/80" aria-hidden="true" />
                <span>{{ __('Shared Host Radar will live on :server when it ships.', ['server' => $server->name]) }}</span>
            </div>
        @endif
    </div>

    <div @class([
        'relative bg-gradient-to-b from-brand-cream to-white',
        'px-6 py-7 sm:px-8 sm:py-8' => ! $compact,
        'px-4 py-5' => $compact,
    ])>
        <div @class(['max-w-md', 'text-center sm:text-left' => ! $compact])>
            <h3 class="text-lg font-semibold text-brand-ink">{{ __('One server, many apps') }}</h3>
            <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                {{ __('Catch the hidden couplings on multi-site VMs — per-site CPU and memory attribution, shared Redis and database maps, and deploy-driven contention before neighbors starve each other.') }}
            </p>
        </div>

        <ul @class([
            'mt-6 grid gap-3 sm:grid-cols-3' => ! $compact,
            'mt-4 space-y-3' => $compact,
        ])>
            @foreach ([
                ['icon' => 'chart-bar-square', 'title' => __('Site load attribution'), 'body' => __('Map running processes to each site path from an SSH snapshot.')],
                ['icon' => 'share', 'title' => __('Shared stack map'), 'body' => __('See which sites depend on the same Redis or database engine.')],
                ['icon' => 'clock', 'title' => __('Contention timeline'), 'body' => __('Correlate deploys and CPU spikes with noisy-neighbor warnings.')],
            ] as $item)
                <li class="rounded-xl border border-brand-ink/10 bg-white p-4 shadow-sm ring-1 ring-brand-ink/[0.03]">
                    <x-dynamic-component :component="'heroicon-o-'.$item['icon']" class="h-5 w-5 text-brand-forest" aria-hidden="true" />
                    <p class="mt-2 text-sm font-semibold text-brand-ink">{{ $item['title'] }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $item['body'] }}</p>
                </li>
            @endforeach
        </ul>
    </div>
</div>
