@props([
    'compact' => false,
    'server' => null,
])

@php
    $serverName = $server?->name ?: __('this server');
@endphp

<div
    @class([
        'relative overflow-hidden',
        'rounded-2xl border border-brand-ink/10 bg-white shadow-sm ring-1 ring-brand-ink/[0.04]' => ! $compact,
        'rounded-xl' => $compact,
    ])
>
    <div @class([
        'relative overflow-hidden bg-[#12101a]',
        'px-5 pb-6 pt-5 sm:px-6 sm:pb-7 sm:pt-6' => ! $compact,
        'px-4 pb-4 pt-3.5' => $compact,
    ])>
        <div class="pointer-events-none absolute -end-16 -top-20 h-56 w-56 rounded-full bg-violet-400/10 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-24 start-8 h-48 w-48 rounded-full bg-brand-sage/10 blur-3xl" aria-hidden="true"></div>

        <div class="relative flex items-start justify-between gap-3">
            <div class="flex items-center gap-2">
                <x-mac-window-dots />
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-violet-200/90">
                <span class="relative flex h-1.5 w-1.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-violet-400/60 opacity-75"></span>
                    <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-violet-400"></span>
                </span>
                {{ __('Coming soon') }}
            </span>
        </div>

        <div class="relative mt-5 space-y-2 font-mono text-[11px] leading-relaxed sm:text-xs">
            <p class="text-slate-500">{{ __('# Blueprint snapshot preview') }}</p>
            <div class="mt-3 rounded-lg border border-white/8 bg-white/[0.03] px-3 py-2.5">
                <p class="text-violet-200/90">{{ __('name: Production golden') }}</p>
                <p class="mt-1 text-slate-400">{{ __('webserver: nginx · php: 8.4 · database: mysql84') }}</p>
                <p class="text-slate-400">{{ __('cache: redis · runtimes: node20, python312') }}</p>
            </div>
            <div class="rounded-lg border border-white/8 bg-white/[0.03] px-3 py-2.5">
                <p class="text-slate-300">{{ __('firewall: ufw baseline (22, 80, 443)') }}</p>
                <p class="mt-1 text-slate-500">{{ __('daemons: horizon, queue-worker templates (reference-only in v1)') }}</p>
            </div>
        </div>

        @if ($server)
            <div class="relative mt-4 inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-[11px] text-slate-300">
                <x-heroicon-o-document-duplicate class="h-3.5 w-3.5 shrink-0 text-violet-300/80" aria-hidden="true" />
                <span>{{ __('Blueprints will capture :server and reuse its stack in the create wizard.', ['server' => $serverName]) }}</span>
            </div>
        @endif
    </div>

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
                    'inline-flex items-center justify-center rounded-xl bg-violet-50 text-violet-800 ring-1 ring-violet-200/80',
                    'h-11 w-11' => ! $compact,
                    'h-9 w-9' => $compact,
                ])>
                    <x-heroicon-o-document-duplicate @class([
                        'shrink-0',
                        'h-6 w-6' => ! $compact,
                        'h-5 w-5' => $compact,
                    ]) aria-hidden="true" />
                </div>
                <h2 @class([
                    'font-semibold tracking-tight text-brand-ink',
                    'mt-4 text-xl sm:text-2xl' => ! $compact,
                    'mt-3 text-base' => $compact,
                ])>{{ __('Server blueprints') }}</h2>
                <p @class([
                    'leading-6 text-brand-moss',
                    'mt-2 text-sm sm:text-[15px]' => ! $compact,
                    'mt-1.5 text-xs' => $compact,
                ])>
                    {{ __('Capture a reconciled VM stack as an org-wide golden template — webserver, PHP, database, cache, runtimes, firewall, and daemon baselines — then apply it when provisioning the next server.') }}
                </p>
            </div>
        </div>

        <ul @class([
            'grid gap-3',
            'mt-7 sm:grid-cols-2' => ! $compact,
            'mt-4' => $compact,
        ])>
            @foreach ([
                ['icon' => 'camera', 'title' => __('Capture from server'), 'body' => __('Snapshot the installed stack from a ready VM without manual copy-paste.')],
                ['icon' => 'rocket-launch', 'title' => __('Apply in create wizard'), 'body' => __('Pre-fill Step 3 when launching a new VM from your org blueprint library.')],
                ['icon' => 'rectangle-stack', 'title' => __('Org library'), 'body' => __('Save multiple golden templates per organization with source-server provenance.')],
                ['icon' => 'shield-check', 'title' => __('Baseline templates'), 'body' => __('Firewall and daemon rows ship as reference-only guidance in v1 — full apply comes later.')],
            ] as $feature)
                <li @class([
                    'flex gap-3 rounded-xl border border-brand-ink/8 bg-white/90 p-3.5 shadow-sm ring-1 ring-brand-ink/[0.03]',
                    'sm:p-4' => ! $compact,
                ])>
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sand/35 text-brand-forest ring-1 ring-brand-ink/8">
                        @switch($feature['icon'])
                            @case('camera')
                                <x-heroicon-o-camera class="h-4 w-4" aria-hidden="true" />
                                @break
                            @case('rocket-launch')
                                <x-heroicon-o-rocket-launch class="h-4 w-4" aria-hidden="true" />
                                @break
                            @case('rectangle-stack')
                                <x-heroicon-o-rectangle-stack class="h-4 w-4" aria-hidden="true" />
                                @break
                            @default
                                <x-heroicon-o-shield-check class="h-4 w-4" aria-hidden="true" />
                        @endswitch
                    </span>
                    <span class="min-w-0 text-left">
                        <span @class(['block font-semibold text-brand-ink', 'text-sm' => $compact])>{{ $feature['title'] }}</span>
                        <span @class(['mt-0.5 block leading-5 text-brand-moss', 'text-xs' => $compact, 'text-[13px]' => ! $compact])>{{ $feature['body'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>

        <div @class([
            'flex flex-col gap-3 border-t border-brand-ink/8 sm:flex-row sm:items-center sm:justify-between',
            'mt-7 pt-5' => ! $compact,
            'mt-5 pt-4' => $compact,
        ])>
            <p @class(['text-brand-moss', 'text-sm' => ! $compact, 'text-xs' => $compact])>
                {{ __('We will enable server blueprints for your org when capture + wizard apply are validated.') }}
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
