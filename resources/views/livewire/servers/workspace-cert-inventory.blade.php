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

    $overallLabel = match ($report['overall']) {
        'critical' => __('Renewals needed'),
        'warning' => __('Review expiring'),
        default => __('Healthy'),
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
    hide-hero
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <section class="dply-card min-w-0 overflow-hidden p-0">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-900 ring-1 ring-amber-200">
                        <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Certificates') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('TLS inventory across every site on this server — expiry windows, challenge type, provider, and bulk renewal for managed certs.') }}
                        </p>
                    </div>
                </div>
                <div @class(['inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ring-1', $overallTone])>
                    @switch($report['overall'])
                        @case('critical')
                            <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            @break
                        @case('warning')
                            <x-heroicon-o-exclamation-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            @break
                        @default
                            <x-heroicon-o-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    @endswitch
                    {{ $overallLabel }}
                </div>
            </div>
        </div>

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Certificate sections')" scroll bare class="!mb-0">
                <x-server-workspace-tab
                    id="cert-tab-inventory"
                    icon="heroicon-o-lock-closed"
                    :active="$cert_workspace_tab === 'inventory'"
                    wire:click="setCertWorkspaceTab('inventory')"
                >
                    {{ __('Certificates') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="cert-tab-notifications"
                    icon="heroicon-o-bell"
                    :active="$cert_workspace_tab === 'notifications'"
                    wire:click="setCertWorkspaceTab('notifications')"
                >
                    {{ __('Notifications') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        <div wire:loading.block wire:target="setCertWorkspaceTab" class="px-5 py-6 sm:px-6" aria-busy="true">
            <span class="sr-only">{{ __('Loading…') }}</span>
            <div class="space-y-3" aria-hidden="true">
                <div class="flex items-start gap-3">
                    <span class="h-9 w-9 shrink-0 animate-pulse rounded-xl bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </div>
                @foreach (range(1, 4) as $row)
                    <div class="flex items-start gap-3 border-t border-brand-ink/10 pt-3">
                        <span class="mt-1 h-5 w-14 shrink-0 animate-pulse rounded-full bg-brand-ink/10"></span>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-3/4 max-w-md animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div wire:loading.remove wire:target="setCertWorkspaceTab">
            @if ($cert_workspace_tab === 'inventory')
                <div>
                    @if ($isDeployer)
                        <div class="border-b border-amber-200/80 bg-amber-50/60 px-5 py-4 sm:px-6">
                            <div class="flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-900 ring-1 ring-amber-200">
                                    <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Read-only') }}</p>
                                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Deployer role') }}</h3>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Deployers can review certificate expiry but cannot queue renewals.') }}</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Compact summary bar (status pill already lives in the page identity). --}}
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-5 py-3.5 sm:px-6">
                        <p class="min-w-0 text-sm text-brand-moss">
                            <span class="font-medium text-brand-ink">
                                {{ trans_choice(
                                    ':count cert across :sites site|:count certs across :sites sites',
                                    $report['summary']['total'],
                                    ['count' => $report['summary']['total'], 'sites' => $report['summary']['sites_with_certs']],
                                ) }}
                            </span>
                            <span class="text-brand-mist"> · </span>
                            {{ __('Warn ≤:warningd d · Crit ≤:criticald d', ['warningd' => $report['warning_days'], 'criticald' => $report['critical_days']]) }}
                        </p>
                        @if (! $isDeployer && $bulkRenewEligible)
                            <button
                                type="button"
                                wire:click="openRenewModal"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                                {{ __('Bulk renew') }}
                            </button>
                        @endif
                    </div>

                    @if ($report['alert_count'] > 0)
                        <ul class="divide-y divide-brand-ink/10 border-b border-brand-ink/10">
                            @foreach ($report['alerts'] as $alert)
                                @php
                                    $alertTone = match ($alert['severity']) {
                                        'critical' => $tonePalette['rose'],
                                        'warning' => $tonePalette['amber'],
                                        default => $tonePalette['sage'],
                                    };
                                @endphp
                                <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-3.5 sm:px-6">
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
                    @endif

                    {{-- Uniform metric tiles — one strip instead of two uneven columns. --}}
                    <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                            @foreach ([
                                ['label' => __('Total'), 'value' => $report['summary']['total'], 'tone' => 'ink'],
                                ['label' => __('Active'), 'value' => $report['summary']['active'], 'tone' => 'emerald'],
                                ['label' => __('Expiring'), 'value' => $report['summary']['expiring'], 'tone' => $report['summary']['expiring'] > 0 ? 'amber' : 'ink'],
                                ['label' => __('Failed'), 'value' => $report['summary']['failed'], 'tone' => $report['summary']['failed'] > 0 ? 'rose' : 'ink'],
                                ['label' => __('Pending'), 'value' => $report['summary']['pending'], 'tone' => 'ink'],
                                ['label' => __('Sites'), 'value' => $report['summary']['sites_with_certs'].' / '.$report['summary']['sites_total'], 'tone' => 'ink'],
                            ] as $stat)
                                <div @class([
                                    'rounded-xl border px-3 py-2.5',
                                    'border-rose-200/80 bg-rose-50/50' => $stat['tone'] === 'rose',
                                    'border-amber-200/80 bg-amber-50/50' => $stat['tone'] === 'amber',
                                    'border-emerald-200/70 bg-emerald-50/40' => $stat['tone'] === 'emerald',
                                    'border-brand-ink/10 bg-brand-sand/20' => $stat['tone'] === 'ink',
                                ])>
                                    <p class="text-2xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ $stat['label'] }}</p>
                                    <p @class([
                                        'mt-1 text-xl font-semibold tabular-nums',
                                        'text-rose-700' => $stat['tone'] === 'rose',
                                        'text-amber-800' => $stat['tone'] === 'amber',
                                        'text-emerald-700' => $stat['tone'] === 'emerald',
                                        'text-brand-ink' => $stat['tone'] === 'ink',
                                    ])>{{ $stat['value'] }}</p>
                                </div>
                            @endforeach
                        </div>

                        @if (count($report['breakdown']['providers'] ?? []) > 0 || count($report['breakdown']['challenges'] ?? []) > 0 || ($report['summary']['expired'] ?? 0) > 0 || ($report['summary']['renewable'] ?? 0) > 0)
                            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-brand-moss">
                                @if (($report['summary']['renewable'] ?? 0) > 0)
                                    <span>{{ __('Renewable') }} <span class="font-semibold tabular-nums text-brand-ink">{{ $report['summary']['renewable'] }}</span></span>
                                @endif
                                @if (($report['summary']['expired'] ?? 0) > 0)
                                    <span>{{ __('Expired') }} <span class="font-semibold tabular-nums text-rose-700">{{ $report['summary']['expired'] }}</span></span>
                                @endif
                                @foreach ($report['breakdown']['providers'] ?? [] as $provider => $count)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/50 px-2 py-0.5 text-xs font-medium text-brand-ink ring-1 ring-brand-ink/10">
                                        {{ $provider }}
                                        <span class="tabular-nums text-brand-moss">{{ $count }}</span>
                                    </span>
                                @endforeach
                                @foreach ($report['breakdown']['challenges'] ?? [] as $challenge => $count)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/50 px-2 py-0.5 text-xs font-medium uppercase text-brand-ink ring-1 ring-brand-ink/10">
                                        {{ $challenge }}
                                        <span class="normal-case tabular-nums text-brand-moss">{{ $count }}</span>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Inventory') }}</h3>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Failed and expiring rows float to the top.') }}</p>
                            </div>
                            <div class="w-full sm:w-auto sm:min-w-[16rem]">
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
                        <div class="mt-3 flex flex-wrap gap-1.5">
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
                                        'rounded-full px-2.5 py-1 text-xs font-semibold ring-1 transition',
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
                        <div class="border-b border-brand-ink/10 px-5 py-10 text-center sm:px-6">
                            <p class="text-sm font-medium text-brand-ink">{{ __('No certificates yet') }}</p>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Issue TLS from a site\'s Certificates section — records appear here once provisioned.') }}</p>
                            <a href="{{ route('servers.sites', $server) }}" wire:navigate class="mt-4 inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                                {{ __('Browse sites') }}
                                <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                            </a>
                        </div>
                    @elseif (count($filteredItems) === 0)
                        <p class="border-b border-brand-ink/10 px-5 py-8 text-center text-sm text-brand-moss sm:px-6">{{ __('No certificates match this filter.') }}</p>
                    @else
                        <div class="overflow-x-auto border-b border-brand-ink/10">
                            <table class="min-w-full text-left text-xs">
                                <thead class="bg-brand-sand/30 text-brand-moss">
                                    <tr>
                                        <th class="px-3 py-2 font-semibold sm:px-5">{{ __('Site') }}</th>
                                        <th class="px-3 py-2 font-semibold">{{ __('Domain(s)') }}</th>
                                        <th class="px-3 py-2 font-semibold">{{ __('Status') }}</th>
                                        <th class="px-3 py-2 font-semibold">{{ __('Provider') }}</th>
                                        <th class="px-3 py-2 font-semibold">{{ __('Challenge') }}</th>
                                        <th class="px-3 py-2 font-semibold">{{ __('Expires') }}</th>
                                        @if (! $isDeployer)
                                            <th class="px-3 py-2 font-semibold text-right sm:pr-5">{{ __('Actions') }}</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5 bg-white">
                                    @foreach ($filteredItems as $item)
                                        <tr @class([
                                            'bg-rose-50/40' => $item['severity'] === 'critical',
                                            'bg-amber-50/30' => $item['severity'] === 'warning',
                                        ])>
                                            <td class="px-3 py-2 sm:px-5">
                                                @if ($item['href'])
                                                    <a href="{{ $item['href'] }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ $item['site_name'] }}</a>
                                                @else
                                                    <span class="font-medium text-brand-ink">{{ $item['site_name'] }}</span>
                                                @endif
                                                @if (($item['scope_type'] ?? '') === SiteCertificate::SCOPE_PREVIEW)
                                                    <p class="mt-0.5 text-2xs text-brand-mist">{{ __('Preview') }}</p>
                                                @endif
                                            </td>
                                            <td class="max-w-[14rem] px-3 py-2">
                                                <p class="font-mono text-brand-ink">{{ $item['domain'] }}</p>
                                                @if (count($item['all_domains'] ?? []) > 1)
                                                    <p class="mt-0.5 text-2xs text-brand-mist" title="{{ implode(', ', $item['all_domains']) }}">
                                                        +{{ count($item['all_domains']) - 1 }} {{ __('SAN') }}
                                                    </p>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2">
                                                <span class="inline-flex rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1 {{ $statusTone($item['status'], $item['severity']) }}">
                                                    {{ $item['status'] }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2">
                                                <span class="inline-flex rounded-full px-2 py-0.5 text-2xs font-semibold ring-1 {{ $tonePalette['mist'] }}">
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
                                                <td class="px-3 py-2 text-right sm:pr-5">
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

                    @include('livewire.servers.partials._live-server-certs', [
                        'liveCertsTitle' => __('Live certificates on server'),
                        'liveCertsDescription' => __('Actual certs on disk — including Caddy automatic-HTTPS certs that aren\'t in the managed records above — with real expiry from openssl.'),
                        'liveCertsWrapperClass' => 'border-t border-brand-ink/10',
                    ])
                </div>
            @endif

            @if ($cert_workspace_tab === 'notifications')
                @include('livewire.servers.partials.cert-inventory.notifications-tab')
            @endif
        </div>
    </section>

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

    @include('livewire.partials.create-notification-channel-modal')
</x-server-workspace-layout>
