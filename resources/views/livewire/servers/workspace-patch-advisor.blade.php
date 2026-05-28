@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
    ];

    $overallTone = match ($report['overall']) {
        'critical' => $tonePalette['rose'],
        'warning' => $tonePalette['amber'],
        default => $tonePalette['emerald'],
    };

    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
@endphp

<x-server-workspace-layout
    :server="$server"
    active="patches"
    :title="__('Patch advisor')"
    :description="__('Pending apt updates, reboot flags, uptime, and unattended-upgrades state — read-only from the inventory probe.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('This page rolls up data already collected by the inventory probe over root SSH. Use Refresh scan to re-run the probe, or open Manage → Updates to apply patches.') }}</p>
    </x-explainer>

    <div class="space-y-6">
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $overallTone }}">
                            <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Overall') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                                @switch($report['overall'])
                                    @case('critical') {{ __('Action needed') }} @break
                                    @case('warning') {{ __('Review updates') }} @break
                                    @default {{ __('Up to date') }}
                                @endswitch
                            </h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                @if ($report['inventory']['checked_at'])
                                    {{ __('Last scan :time', ['time' => $report['inventory']['checked_at']->diffForHumans()]) }}
                                    @if ($report['inventory']['stale'])
                                        · <span class="font-medium text-amber-800">{{ __('stale') }}</span>
                                    @endif
                                @else
                                    {{ __('No inventory scan on record yet.') }}
                                @endif
                                @if ($report['os']['pretty'])
                                    · {{ $report['os']['pretty'] }}
                                @endif
                            </p>
                        </div>
                    </div>
                    @if ($opsReady && ! $isDeployer)
                        <button
                            type="button"
                            wire:click="refreshServerInventoryDetails"
                            wire:loading.attr="disabled"
                            wire:target="refreshServerInventoryDetails"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Refresh scan') }}
                            </span>
                            <span wire:loading wire:target="refreshServerInventoryDetails" class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                                {{ __('Scanning…') }}
                            </span>
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
                                    @if ($alert['severity'] === 'critical')
                                        <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                                    @else
                                        <x-heroicon-o-information-circle class="h-4 w-4" aria-hidden="true" />
                                    @endif
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-ink">{{ $alert['title'] }}</p>
                                    <p class="mt-0.5 text-sm text-brand-moss">{{ $alert['message'] }}</p>
                                </div>
                            </div>
                            @if ($alert['href'] && $alert['link_label'])
                                <a href="{{ $alert['href'] }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                    {{ $alert['link_label'] }}
                                    <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="px-6 py-5 text-sm text-brand-moss sm:px-7">
                    {{ __('No patch or reboot alerts from the latest inventory scan.') }}
                </div>
            @endif
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Package updates') }}</h2>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Debian/Ubuntu apt upgradable packages from the last probe.') }}</p>
                </div>
                <div class="px-6 py-4 sm:px-7">
                    <dl class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Total pending') }}</dt>
                            <dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $report['packages']['total'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Security') }}</dt>
                            <dd class="mt-1 text-2xl font-semibold {{ $report['packages']['security'] > 0 ? 'text-rose-700' : 'text-brand-ink' }}">
                                {{ $report['packages']['security'] }}
                            </dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Last apt update') }}</dt>
                            <dd class="mt-1 text-brand-ink">
                                @if ($report['inventory']['last_apt_update'])
                                    {{ $report['inventory']['last_apt_update']->timezone(config('app.timezone'))->format('Y-m-d H:i T') }}
                                    <span class="text-brand-moss">({{ $report['inventory']['last_apt_update']->diffForHumans() }})</span>
                                @else
                                    <span class="text-brand-moss">{{ __('Unknown') }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if (count($report['packages']['rows']) > 0)
                        <div class="mt-5 overflow-x-auto rounded-xl border border-brand-ink/10">
                            <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                                <thead class="bg-brand-sand/30 text-brand-moss">
                                    <tr>
                                        <th class="px-3 py-2 font-semibold">{{ __('Package') }}</th>
                                        <th class="px-3 py-2 font-semibold">{{ __('Version') }}</th>
                                        <th class="px-3 py-2 font-semibold">{{ __('Source') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5 bg-white">
                                    @foreach ($report['packages']['rows'] as $row)
                                        <tr @class(['bg-rose-50/40' => $row['is_security']])>
                                            <td class="px-3 py-2 font-medium text-brand-ink">{{ $row['name'] }}</td>
                                            <td class="px-3 py-2 text-brand-moss">
                                                @if ($row['current_version'])
                                                    {{ $row['current_version'] }} →
                                                @endif
                                                {{ $row['new_version'] }}
                                            </td>
                                            <td class="px-3 py-2 text-brand-moss">{{ $row['sources'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($report['packages']['preview_truncated'])
                            <p class="mt-2 text-xs text-brand-moss">{{ __('Package preview was truncated on the server — run Refresh scan or open Manage → Updates for the full list.') }}</p>
                        @endif
                    @elseif (! $report['supports_apt'] && ! $report['inventory']['never_scanned'])
                        <p class="mt-4 text-sm text-brand-moss">{{ __('apt inventory is not available on this OS yet — only Debian/Ubuntu hosts are supported in v1.') }}</p>
                    @endif

                    <div class="mt-4">
                        <a href="{{ route('servers.manage', $server).'?section=updates' }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                            {{ __('Manage → Updates') }}
                            <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                        </a>
                    </div>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Reboot & uptime') }}</h2>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Kernel reboot flag and live uptime from the extended probe.') }}</p>
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
                            <a href="{{ route('servers.manage', $server).'?section=danger' }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-50">
                                {{ __('Manage → Danger (reboot)') }}
                            </a>
                        </div>
                    @endif

                    <div class="border-t border-brand-ink/10 pt-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Unattended upgrades') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">
                            @if (! $report['unattended']['present'])
                                {{ __('unattended-upgrades package not detected.') }}
                            @elseif ($report['unattended']['enabled'] === true)
                                {{ __('Enabled — security patches may apply automatically.') }}
                            @elseif ($report['unattended']['enabled'] === false)
                                {{ __('Installed but disabled in apt config.') }}
                            @else
                                {{ __('Present — enable state could not be determined.') }}
                            @endif
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-server-workspace-layout>
