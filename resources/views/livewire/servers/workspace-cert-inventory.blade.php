@php
    use App\Models\SiteCertificate;

    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'mist' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
    ];

    $overallTone = match ($report['overall']) {
        'critical' => $tonePalette['rose'],
        'warning' => $tonePalette['amber'],
        default => $tonePalette['emerald'],
    };

    $statusTone = static function (string $status, string $severity) use ($tonePalette): string {
        if ($severity === 'critical') {
            return $tonePalette['rose'];
        }
        if ($severity === 'warning') {
            return $tonePalette['amber'];
        }

        return match ($status) {
            SiteCertificate::STATUS_ACTIVE => $tonePalette['emerald'],
            SiteCertificate::STATUS_FAILED, SiteCertificate::STATUS_EXPIRED => $tonePalette['rose'],
            default => $tonePalette['mist'],
        };
    };

    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="cert-inventory"
    :title="__('Certificates')"
    :description="__('TLS inventory across every site on this server — expiry windows, challenge type, provider, and bulk renewal for managed certs.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('Ops teams renew per-server. This page rolls up Dply-managed certificates (Let\'s Encrypt, ZeroSSL, imported) from all sites on the host, sorted by urgency. Bulk renew queues jobs for failed certs and anything expiring within :warning days.', ['warning' => $report['warning_days']]) }}</p>
    </x-explainer>

    <div class="space-y-6">
        @if ($isDeployer)
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['amber'] }}">
                            <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Deployers can review certificate expiry but cannot queue renewals.') }}</p>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $overallTone }}">
                            <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Overall') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                                @switch($report['overall'])
                                    @case('critical') {{ __('Renewals needed') }} @break
                                    @case('warning') {{ __('Review expiring certs') }} @break
                                    @default {{ __('TLS coverage healthy') }}
                                @endswitch
                            </h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ trans_choice(
                                    ':count cert across :sites site|:count certs across :sites sites',
                                    $report['summary']['total'],
                                    ['count' => $report['summary']['total'], 'sites' => $report['summary']['sites_with_certs']],
                                ) }}
                                · {{ __('Warning :warningd · Critical :criticald', ['warningd' => $report['warning_days'], 'criticald' => $report['critical_days']]) }}
                            </p>
                        </div>
                    </div>
                    @if (! $isDeployer && $bulkRenewEligible)
                        <button
                            type="button"
                            wire:click="openRenewModal"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Bulk renew') }}
                        </button>
                    @endif
                </div>
            </div>

            @if ($report['alert_count'] > 0)
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($report['alerts'] as $alert)
                        @php
                            $alertTone = match ($alert['severity']) {
                                'critical' => $tonePalette['rose'],
                                'warning' => $tonePalette['amber'],
                                default => $tonePalette['sage'],
                            };
                        @endphp
                        <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 sm:px-7">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 {{ $alertTone }}">
                                    <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $alert['title'] }}</p>
                                    <p class="mt-0.5 text-sm text-brand-moss">{{ $alert['message'] }}</p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">
                    {{ __('No failed or expiring certificate alerts on this server.') }}
                </div>
            @endif
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Certificate stats') }}</h2>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Counts from Dply site certificate records.') }}</p>
                </div>
                <div class="px-6 py-4 sm:px-7">
                    <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Total') }}</dt>
                            <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $report['summary']['total'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Active') }}</dt>
                            <dd class="mt-1 text-2xl font-semibold text-emerald-700">{{ $report['summary']['active'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Expiring') }}</dt>
                            <dd class="mt-1 text-2xl font-semibold {{ $report['summary']['expiring'] > 0 ? 'text-amber-800' : 'text-brand-ink' }}">
                                {{ $report['summary']['expiring'] }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Failed') }}</dt>
                            <dd class="mt-1 text-2xl font-semibold {{ $report['summary']['failed'] > 0 ? 'text-rose-700' : 'text-brand-ink' }}">
                                {{ $report['summary']['failed'] }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Pending') }}</dt>
                            <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $report['summary']['pending'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Renewable') }}</dt>
                            <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $report['summary']['renewable'] }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Coverage') }}</h2>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Sites with at least one certificate vs total sites on this server.') }}</p>
                </div>
                <div class="space-y-4 px-6 py-4 text-sm sm:px-7">
                    <dl class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Sites with certs') }}</dt>
                            <dd class="mt-1 text-2xl font-semibold text-brand-ink">
                                {{ $report['summary']['sites_with_certs'] }}
                                <span class="text-base font-normal text-brand-moss">/ {{ $report['summary']['sites_total'] }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Expired') }}</dt>
                            <dd class="mt-1 text-2xl font-semibold {{ $report['summary']['expired'] > 0 ? 'text-rose-700' : 'text-brand-ink' }}">
                                {{ $report['summary']['expired'] }}
                            </dd>
                        </div>
                    </dl>

                    @if (count($report['breakdown']['providers']) > 0)
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('By provider') }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($report['breakdown']['providers'] as $provider => $count)
                                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 {{ $tonePalette['mist'] }}">
                                        {{ $provider }}
                                        <span class="font-mono text-brand-ink">{{ $count }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if (count($report['breakdown']['challenges']) > 0)
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('By challenge') }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($report['breakdown']['challenges'] as $challenge => $count)
                                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase ring-1 {{ $tonePalette['mist'] }}">
                                        {{ $challenge }}
                                        <span class="font-mono text-brand-ink">{{ $count }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        </div>

        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('All certificates') }}</h2>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Sorted by urgency — failed and expiring rows float to the top.') }}</p>
                    </div>
                    <div class="flex w-full flex-col gap-2 sm:w-auto sm:min-w-[14rem]">
                        <label for="cert-search" class="sr-only">{{ __('Search certificates') }}</label>
                        <input
                            id="cert-search"
                            type="search"
                            wire:model.live.debounce.300ms="certSearch"
                            placeholder="{{ __('Search site or domain…') }}"
                            class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-1 focus:ring-brand-sage"
                        />
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ([
                        'all' => __('All'),
                        'attention' => __('Needs attention'),
                        'failed' => __('Failed'),
                        'expiring' => __('Expiring'),
                        'pending' => __('Pending'),
                        'active' => __('Active'),
                    ] as $key => $label)
                        <button
                            type="button"
                            wire:click="setCertFilter(@js($key))"
                            @class([
                                'rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 transition',
                                $certFilter === $key
                                    ? 'bg-brand-forest text-white ring-brand-forest'
                                    : 'bg-white text-brand-moss ring-brand-ink/15 hover:bg-brand-sand/40',
                            ])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($report['summary']['total'] === 0)
                <div class="px-6 py-10 text-center sm:px-7">
                    <p class="text-sm font-medium text-brand-ink">{{ __('No certificates yet') }}</p>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Issue TLS from a site\'s Certificates section — records appear here once provisioned.') }}</p>
                    <a href="{{ route('servers.sites', $server) }}" wire:navigate class="mt-4 inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                        {{ __('Browse sites') }}
                        <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                    </a>
                </div>
            @elseif (count($filteredItems) === 0)
                <p class="px-6 py-8 text-center text-sm text-brand-moss sm:px-7">{{ __('No certificates match this filter.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-brand-sand/30 text-brand-moss">
                            <tr>
                                <th class="px-3 py-2 font-semibold">{{ __('Site') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Domain(s)') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Status') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Provider') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Challenge') }}</th>
                                <th class="px-3 py-2 font-semibold">{{ __('Expires') }}</th>
                                @if (! $isDeployer)
                                    <th class="px-3 py-2 font-semibold text-right">{{ __('Actions') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white">
                            @foreach ($filteredItems as $item)
                                <tr @class([
                                    'bg-rose-50/40' => $item['severity'] === 'critical',
                                    'bg-amber-50/30' => $item['severity'] === 'warning',
                                ])>
                                    <td class="px-3 py-2">
                                        @if ($item['href'])
                                            <a href="{{ $item['href'] }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ $item['site_name'] }}</a>
                                        @else
                                            <span class="font-medium text-brand-ink">{{ $item['site_name'] }}</span>
                                        @endif
                                        @if (($item['scope_type'] ?? '') === SiteCertificate::SCOPE_PREVIEW)
                                            <p class="mt-0.5 text-[10px] text-brand-mist">{{ __('Preview') }}</p>
                                        @endif
                                    </td>
                                    <td class="max-w-[14rem] px-3 py-2">
                                        <p class="font-mono text-brand-ink">{{ $item['domain'] }}</p>
                                        @if (count($item['all_domains'] ?? []) > 1)
                                            <p class="mt-0.5 text-[10px] text-brand-mist" title="{{ implode(', ', $item['all_domains']) }}">
                                                +{{ count($item['all_domains']) - 1 }} {{ __('SAN') }}
                                            </p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $statusTone($item['status'], $item['severity']) }}">
                                            {{ $item['status'] }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 {{ $tonePalette['mist'] }}">
                                            {{ $item['provider'] }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 font-mono uppercase text-brand-moss">{{ $item['challenge'] }}</td>
                                    <td class="px-3 py-2">
                                        @if ($item['expires_at'])
                                            <span @class([
                                                'font-semibold' => $item['severity'] !== 'ok',
                                                'text-rose-700' => $item['severity'] === 'critical' && ($item['days_left'] ?? 999) <= $report['critical_days'],
                                                'text-amber-800' => $item['severity'] === 'warning',
                                                'text-brand-ink' => $item['severity'] === 'ok',
                                            ])>
                                                {{ $item['expires_at']->format('Y-m-d') }}
                                            </span>
                                            <span class="text-brand-moss">({{ $item['days_left'] }}d)</span>
                                        @else
                                            <span class="text-brand-mist">—</span>
                                        @endif
                                    </td>
                                    @if (! $isDeployer)
                                        <td class="px-3 py-2 text-right">
                                            <div class="inline-flex items-center gap-2">
                                                @if ($item['href'])
                                                    <a href="{{ $item['href'] }}" wire:navigate class="font-semibold text-brand-forest hover:underline">{{ __('Manage') }}</a>
                                                @endif
                                                @if ($item['renewable'] && (in_array($item['status'], [SiteCertificate::STATUS_FAILED, SiteCertificate::STATUS_EXPIRED], true) || (($item['days_left'] ?? 999) <= $report['warning_days'])))
                                                    <button
                                                        type="button"
                                                        wire:click="queueSingleRenew('{{ $item['id'] }}')"
                                                        wire:loading.attr="disabled"
                                                        wire:target="queueSingleRenew('{{ $item['id'] }}')"
                                                        class="font-semibold text-brand-forest hover:underline disabled:opacity-50"
                                                    >
                                                        {{ __('Renew') }}
                                                    </button>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <x-modal name="cert-inventory-renew" :show="$showRenewModal" wire:model="showRenewModal">
        <div class="space-y-4 p-6">
            <div>
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Bulk renew certificates?') }}</h3>
                <p class="mt-2 text-sm text-brand-moss">
                    {{ __('Queues renewal jobs for managed Let\'s Encrypt / ZeroSSL certificates that are failed, expired, or expiring within :days days on this server.', ['days' => $report['warning_days']]) }}
                </p>
            </div>
            <dl class="grid grid-cols-2 gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4 text-sm">
                <div>
                    <dt class="text-xs font-medium uppercase text-brand-mist">{{ __('Eligible now') }}</dt>
                    <dd class="mt-1 font-semibold text-brand-ink">{{ $bulkRenewEligible ? __('Yes') : __('None') }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase text-brand-mist">{{ __('Renewable total') }}</dt>
                    <dd class="mt-1 font-semibold text-brand-ink">{{ $report['summary']['renewable'] }}</dd>
                </div>
            </dl>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="closeRenewModal" class="rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                    {{ __('Cancel') }}
                </button>
                <button
                    type="button"
                    wire:click="queueBulkRenew"
                    wire:loading.attr="disabled"
                    wire:target="queueBulkRenew"
                    @disabled(! $bulkRenewEligible)
                    class="rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ __('Queue renewals') }}
                </button>
            </div>
        </div>
    </x-modal>
</x-server-workspace-layout>
