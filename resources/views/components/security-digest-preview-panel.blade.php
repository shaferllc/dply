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
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-red-400/80" aria-hidden="true"></span>
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-amber-300/80" aria-hidden="true"></span>
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-400/80" aria-hidden="true"></span>
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
            <p class="text-slate-500">{{ __('# Security digest preview — read-only scan') }}</p>
            <p class="mt-3 text-emerald-300">
                <span class="text-slate-400">{{ $prompt }}</span>
                <span class="text-slate-300"> ~ $</span>
                <span class="text-slate-100"> dply security digest</span>
            </p>
            <p class="mt-1 text-slate-400">{{ __('auth.log    218 failed logins  ·  6 source IPs') }}</p>
            <p class="text-amber-300/90">{{ __('fail2ban    sshd jail active  ·  4 banned') }}</p>
            <p class="text-slate-400">{{ __('ufw         active  ·  22,80,443 allow') }}</p>
            <p class="text-emerald-400/90">{{ __('sshd        PasswordAuthentication no  ·  root login no') }}</p>
            <p class="mt-3 text-emerald-300">
                <span class="text-slate-400">{{ $prompt }}</span>
                <span class="text-slate-300"> ~ $</span>
                <span class="inline-block h-4 w-2 animate-pulse bg-emerald-300/90 align-middle" aria-hidden="true"></span>
            </p>
        </div>

        @if ($server)
            <div class="relative mt-4 inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-[11px] text-slate-300">
                <x-heroicon-o-server-stack class="h-3.5 w-3.5 shrink-0 text-emerald-300/80" aria-hidden="true" />
                <span>{{ __('Security digest will live on :server when it ships.', ['server' => $server->name]) }}</span>
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
                    <x-heroicon-o-shield-exclamation @class([
                        'shrink-0',
                        'h-6 w-6' => ! $compact,
                        'h-5 w-5' => $compact,
                    ]) aria-hidden="true" />
                </div>
                <h2 @class([
                    'font-semibold tracking-tight text-brand-ink',
                    'mt-4 text-xl sm:text-2xl' => ! $compact,
                    'mt-3 text-base' => $compact,
                ])>{{ __('Security digest') }}</h2>
                <p @class([
                    'leading-6 text-brand-moss',
                    'mt-2 text-sm sm:text-[15px]' => ! $compact,
                    'mt-1.5 text-xs' => $compact,
                ])>
                    {{ __('A lightweight, read-only posture check over root SSH — SSH auth-failure volume, fail2ban jails, host firewall rules, and sshd hardening, all on one page.') }}
                </p>
            </div>
        </div>

        <ul @class([
            'grid gap-3',
            'mt-7 sm:grid-cols-2' => ! $compact,
            'mt-4' => $compact,
        ])>
            @foreach ([
                ['icon' => 'finger-print', 'title' => __('Auth failures'), 'body' => __('Spot brute-force volume and the top offending source IPs.')],
                ['icon' => 'lock-closed', 'title' => __('fail2ban jails'), 'body' => __('See active jails and currently banned hosts at a glance.')],
                ['icon' => 'shield-check', 'title' => __('Firewall posture'), 'body' => __('Confirm the host firewall is active with the rules you expect.')],
                ['icon' => 'command-line', 'title' => __('sshd hardening'), 'body' => __('Flag password auth and root login that should be disabled.')],
            ] as $feature)
                <li @class([
                    'flex gap-3 rounded-xl border border-brand-ink/8 bg-white/90 p-3.5 shadow-sm ring-1 ring-brand-ink/[0.03]',
                    'sm:p-4' => ! $compact,
                ])>
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sand/35 text-brand-forest ring-1 ring-brand-ink/8">
                        @switch($feature['icon'])
                            @case('finger-print')
                                <x-heroicon-o-finger-print class="h-4 w-4" aria-hidden="true" />
                                @break
                            @case('lock-closed')
                                <x-heroicon-o-lock-closed class="h-4 w-4" aria-hidden="true" />
                                @break
                            @case('command-line')
                                <x-heroicon-o-command-line class="h-4 w-4" aria-hidden="true" />
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
                {{ __('We will enable this for your org when the security digest ships.') }}
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
