@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    ];
    $overallTone = match ($report['overall']) {
        'critical' => $tonePalette['rose'],
        'warning' => $tonePalette['amber'],
        default => $tonePalette['emerald'],
    };
    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
@endphp

<x-server-workspace-layout :server="$server" active="daemon-slo" :title="__('Worker SLOs')" :description="__('Supervisor queue workers and server daemons — RUNNING state, backoff, and config drift.')">
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])
    <x-explainer><p>{{ __('Rolls up the scheduled supervisor health snapshot and per-program supervisorctl status. Refresh over SSH for a live picture before restarting workers.') }}</p></x-explainer>

    <div class="space-y-6">
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $overallTone }}"><x-heroicon-o-server-stack class="h-5 w-5" /></span>
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Supervisor health') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                @if ($report['health']['checked_at'])
                                    {{ __('Last check :time', ['time' => $report['health']['checked_at']->diffForHumans()]) }}
                                @else
                                    {{ __('No health snapshot yet.') }}
                                @endif
                                · {{ trans_choice(':count active program|:count active programs', $report['programs']['active'], ['count' => $report['programs']['active']]) }}
                            </p>
                        </div>
                    </div>
                    @if ($opsReady && ! $isDeployer)
                        <button type="button" wire:click="refreshSupervisorHealth" wire:loading.attr="disabled" wire:target="refreshSupervisorHealth" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <span wire:loading.remove wire:target="refreshSupervisorHealth">{{ __('Refresh status') }}</span>
                            <span wire:loading wire:target="refreshSupervisorHealth">{{ __('Refreshing…') }}</span>
                        </button>
                    @endif
                </div>
            </div>
            @if ($report['alert_count'] > 0)
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($report['alerts'] as $alert)
                        <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 sm:px-7">
                            <div><p class="text-sm font-semibold text-brand-ink">{{ $alert['title'] }}</p><p class="mt-0.5 text-sm text-brand-moss">{{ $alert['message'] }}</p></div>
                            @if ($alert['href'])<a href="{{ $alert['href'] }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ $alert['link_label'] }}</a>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if (count($report['programs']['rows']) > 0)
            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7"><h2 class="text-sm font-semibold text-brand-ink">{{ __('Managed programs') }}</h2></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-brand-sand/30 text-brand-moss"><tr><th class="px-3 py-2">{{ __('Program') }}</th><th class="px-3 py-2">{{ __('Site') }}</th><th class="px-3 py-2">{{ __('State') }}</th><th class="px-3 py-2">{{ __('Uptime') }}</th></tr></thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white">
                            @foreach ($report['programs']['rows'] as $row)
                                <tr @class(['bg-rose-50/40' => ! $row['healthy']])>
                                    <td class="px-3 py-2 font-medium"><a href="{{ $row['href'] }}" wire:navigate class="text-brand-forest hover:underline">{{ $row['slug'] }}</a></td>
                                    <td class="px-3 py-2 text-brand-moss">{{ $row['site_name'] ?? __('Server') }}</td>
                                    <td class="px-3 py-2 font-semibold {{ $row['healthy'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ $row['state'] }}</td>
                                    <td class="px-3 py-2 text-brand-moss">{{ $row['uptime'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-server-workspace-layout>
