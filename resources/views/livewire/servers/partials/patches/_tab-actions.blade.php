@if ($opsReady && ! $isDeployer)
    @php
        $aptActionGroups = [
            __('Prepare') => [
                'apt_update' => [
                    'icon' => 'heroicon-o-arrow-path',
                    'tone' => 'sage',
                ],
            ],
            __('Apply updates') => [
                'apt_upgrade' => [
                    'icon' => 'heroicon-o-arrow-trending-up',
                    'tone' => 'amber',
                    'destructive' => true,
                ],
                'apt_dist_upgrade' => [
                    'icon' => 'heroicon-o-rocket-launch',
                    'tone' => 'rose',
                    'destructive' => true,
                ],
            ],
            __('Housekeeping') => [
                'apt_autoremove' => [
                    'icon' => 'heroicon-o-trash',
                    'tone' => 'neutral',
                ],
                'apt_clean' => [
                    'icon' => 'heroicon-o-archive-box',
                    'tone' => 'neutral',
                ],
            ],
        ];

        $aptToneStyles = [
            'sage' => [
                'icon' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
                'card' => 'border-brand-ink/10 bg-white hover:border-brand-sage/35 hover:bg-brand-sage/5',
                'cta' => 'text-brand-forest',
            ],
            'amber' => [
                'icon' => 'bg-amber-50 text-amber-900 ring-amber-200',
                'card' => 'border-amber-200/80 bg-amber-50/30 hover:border-amber-300 hover:bg-amber-50/70',
                'cta' => 'text-amber-900',
            ],
            'rose' => [
                'icon' => 'bg-rose-50 text-rose-800 ring-rose-200',
                'card' => 'border-rose-200/70 bg-rose-50/20 hover:border-rose-300 hover:bg-rose-50/50',
                'cta' => 'text-rose-800',
            ],
            'neutral' => [
                'icon' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
                'card' => 'border-brand-ink/10 bg-brand-cream/20 hover:border-brand-ink/20 hover:bg-brand-sand/30',
                'cta' => 'text-brand-ink',
            ],
        ];
    @endphp

    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-wrench-screwdriver class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Actions') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Apt actions') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Queued over SSH — output streams in the banner above. Run Refresh scan after upgrades to update the package list.') }}
                </p>
            </div>
            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-sage/15 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-server-stack class="h-4 w-4" aria-hidden="true" />
                {{ __('Debian / Ubuntu') }}
            </span>
        </div>

        <div class="space-y-8 px-6 py-6 sm:px-7">
            @foreach ($aptActionGroups as $groupLabel => $groupActions)
                <div>
                    <h3 class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $groupLabel }}</h3>
                    <div @class([
                        'mt-3 grid gap-3',
                        'max-w-md' => count($groupActions) === 1,
                        'sm:grid-cols-2' => count($groupActions) === 2,
                        'sm:grid-cols-2 lg:grid-cols-3' => count($groupActions) > 2,
                    ])>
                        @foreach ($groupActions as $key => $meta)
                            @if (! empty($serviceActions[$key]))
                                @php
                                    $a = $serviceActions[$key];
                                    $tone = $aptToneStyles[$meta['tone']] ?? $aptToneStyles['neutral'];
                                    $destructive = (bool) ($meta['destructive'] ?? false);
                                @endphp
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('runAllowlistedManageAction', ['{{ $key }}'], @js($a['label']), @js($a['confirm']), @js($a['label']), {{ $destructive ? 'true' : 'false' }})"
                                    class="group flex h-full w-full flex-col items-start gap-4 rounded-2xl border p-4 text-left shadow-sm transition duration-150 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40 {{ $tone['card'] }}"
                                >
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tone['icon'] }}">
                                        @switch($meta['icon'])
                                            @case('heroicon-o-arrow-path')
                                                <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                                                @break
                                            @case('heroicon-o-arrow-trending-up')
                                                <x-heroicon-o-arrow-trending-up class="h-5 w-5" aria-hidden="true" />
                                                @break
                                            @case('heroicon-o-rocket-launch')
                                                <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
                                                @break
                                            @case('heroicon-o-trash')
                                                <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
                                                @break
                                            @case('heroicon-o-archive-box')
                                                <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                                                @break
                                        @endswitch
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold text-brand-ink">{{ $a['label'] }}</span>
                                        @if (! empty($a['description']))
                                            <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ $a['description'] }}</span>
                                        @endif
                                    </span>
                                    <span class="inline-flex items-center gap-1 text-xs font-semibold {{ $tone['cta'] }}">
                                        <span class="opacity-70 transition group-hover:opacity-100">{{ __('Run action') }}</span>
                                        <x-heroicon-m-chevron-right class="h-3.5 w-3.5 transition group-hover:translate-x-0.5" aria-hidden="true" />
                                    </span>
                                </button>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@else
    <section class="dply-card overflow-hidden border-amber-200">
        <div class="flex items-start gap-3 px-6 py-5 sm:px-7">
            <x-icon-badge tone="amber">
                <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Actions unavailable') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">
                    @if ($isDeployer)
                        {{ __('Deployers can view patch state but cannot run apt actions.') }}
                    @else
                        {{ __('Provisioning and SSH must be ready before apt actions work.') }}
                    @endif
                </p>
            </div>
        </div>
    </section>
@endif

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Reboot') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Reboot & uptime') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Kernel reboot flag and live uptime from the extended probe.') }}</p>
        </div>
    </div>
    <div class="space-y-4 px-6 py-4 sm:px-7">
        <div class="flex flex-wrap items-center gap-2">
            @if ($report['reboot']['required'] === true)
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-900">
                    {{ __('Reboot required') }}
                </span>
            @elseif ($report['reboot']['required'] === false)
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-emerald-800">
                    {{ __('No reboot pending') }}
                </span>
            @else
                <span class="text-sm text-brand-moss">{{ __('Reboot status unknown — refresh the inventory scan.') }}</span>
            @endif
        </div>

        @if ($report['uptime']['raw'])
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 font-mono text-xs text-brand-ink">
                {{ $report['uptime']['raw'] }}
            </div>
        @endif

        @if ($report['reboot']['required'] === true)
            <div class="flex flex-wrap gap-3">
                @feature('workspace.server_maintenance')
                    <a href="{{ route('servers.maintenance', $server) }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                        {{ __('Plan maintenance window') }}
                    </a>
                @endfeature
                @if ($opsReady && ! $isDeployer && ! empty($dangerousActions['reboot']))
                    @php $r = $dangerousActions['reboot']; @endphp
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('runAllowlistedManageAction', ['reboot'], @js($r['label']), @js($r['confirm']), @js($r['label']), true)"
                        class="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-sm font-medium text-red-900 hover:bg-red-100"
                    >
                        <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                        {{ $r['label'] }}
                    </button>
                @endif
            </div>
        @endif
    </div>
</section>
