@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'sky' => 'bg-sky-50 text-sky-800 ring-sky-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    ];
    $nudge = is_array($report['nudge'] ?? null) ? $report['nudge'] : null;
    $nudgeTone = match ($nudge['severity'] ?? null) {
        'warning' => $tonePalette['amber'],
        default => $tonePalette['sky'],
    };
    $providerCents = (int) ($report['provider']['monthly_usd_cents'] ?? 0);
    $providerFormatted = $providerCents > 0 ? '$'.number_format($providerCents / 100, 2).'/mo' : __('Unknown');
@endphp

<x-server-workspace-layout
    :server="$server"
    active="cost"
    :title="__('Cost')"
    :description="__('Provider estimate, dply tier fee, site count, and capacity headroom for this server.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('Honest stack math for BYO VMs — not invoiced provider totals. Edit cost notes on Settings when catalog lookup is unavailable.') }}</p>
    </x-explainer>

    <div class="space-y-6">
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                            <x-heroicon-o-currency-dollar class="h-5 w-5" />
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Estimated monthly stack') }}</p>
                            <h2 class="mt-0.5 text-2xl font-semibold tabular-nums text-brand-ink">{{ $report['totals']['formatted'] ?? '—' }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ trans(':provider provider · :dply dply :tier tier · :sites sites', [
                                    'provider' => $providerFormatted,
                                    'dply' => $report['dply']['formatted'] ?? '—',
                                    'tier' => $report['dply']['tier_label'] ?? '—',
                                    'sites' => $report['sites']['count'] ?? 0,
                                ]) }}
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('servers.settings', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Cost notes') }}</a>
                </div>
            </div>

            <dl class="grid gap-4 px-6 py-6 sm:grid-cols-2 sm:px-7">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Provider infrastructure') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ $providerFormatted }}</dd>
                    <dd class="mt-1 text-xs text-brand-moss">
                        @if (($report['provider']['source'] ?? '') === 'catalog')
                            {{ $report['provider']['provider'] ?? '' }} {{ $report['provider']['plan'] ?? '' }} · {{ __('catalog') }}
                        @elseif (($report['provider']['source'] ?? '') === 'note')
                            {{ __('From saved cost note') }}
                        @else
                            {{ $report['provider']['detail'] ?? __('Add a cost note or connect a supported provider credential.') }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Dply platform fee') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ $report['dply']['formatted'] ?? '—' }}</dd>
                    <dd class="mt-1 text-xs text-brand-moss">{{ __('Tier :tier from detected vCPU/RAM', ['tier' => $report['dply']['tier_label'] ?? '—']) }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Sites on this server') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ $report['sites']['count'] ?? 0 }}</dd>
                    <dd class="mt-1 text-xs text-brand-moss"><a href="{{ route('servers.sites', $server) }}" wire:navigate class="font-semibold text-brand-forest hover:underline">{{ __('View sites') }}</a></dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Capacity headroom') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-brand-ink">
                        @if ($report['capacity']['cpu_pct'] !== null)
                            {{ number_format($report['capacity']['cpu_pct'], 0) }}% CPU · {{ number_format((float) $report['capacity']['mem_pct'], 0) }}% mem
                        @else
                            {{ __('Metrics pending') }}
                        @endif
                    </dd>
                    @if ($report['capacity']['headroom_sites'] !== null)
                        <dd class="mt-1 text-xs text-brand-moss">{{ trans_choice('~:count more small site at current load|~:count more small sites at current load', $report['capacity']['headroom_sites'], ['count' => $report['capacity']['headroom_sites']]) }}</dd>
                    @endif
                </div>
            </dl>

            <p class="border-t border-brand-ink/10 px-6 py-4 text-xs leading-relaxed text-brand-moss sm:px-7">{{ $report['disclaimer'] ?? '' }}</p>
        </section>

        @if ($nudge)
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $nudgeTone }}">
                        <x-heroicon-o-light-bulb class="h-5 w-5" />
                    </span>
                    <div>
                        <h2 class="text-base font-semibold text-brand-ink">{{ $nudge['title'] }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">{{ $nudge['message'] }}</p>
                    </div>
                </div>
            </section>
        @endif
    </div>
</x-server-workspace-layout>
