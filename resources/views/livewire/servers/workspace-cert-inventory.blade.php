@php
    $tonePalette = ['amber' => 'bg-amber-50 text-amber-900 ring-amber-200', 'rose' => 'bg-rose-50 text-rose-700 ring-rose-200', 'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'];
    $overallTone = match ($report['overall']) { 'critical' => $tonePalette['rose'], 'warning' => $tonePalette['amber'], default => $tonePalette['emerald'] };
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
@endphp

<x-server-workspace-layout :server="$server" active="cert-inventory" :title="__('Certificates')" :description="__('Every TLS certificate on sites hosted by this server — expiry, challenge type, and renewal status.')">
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])
    <x-explainer><p>{{ __('Ops teams think per-server for certificate renewals. Bulk renew queues managed Let\'s Encrypt / ZeroSSL jobs for failed or expiring certs.') }}</p></x-explainer>

    <div class="space-y-6">
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $overallTone }}"><x-heroicon-o-lock-closed class="h-5 w-5" /></span>
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Certificate inventory') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">{{ __(':total total · :active active · :expiring expiring · :failed failed', $report['summary']) }}</p>
                        </div>
                    </div>
                    @if (! $isDeployer && ($report['summary']['expiring'] > 0 || $report['summary']['failed'] > 0))
                        <button type="button" wire:click="openRenewModal" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Bulk renew') }}</button>
                    @endif
                </div>
            </div>
            @if ($report['summary']['total'] === 0)
                <p class="px-6 py-5 text-sm text-brand-moss sm:px-7">{{ __('No certificates on sites for this server yet.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-brand-sand/30 text-brand-moss"><tr><th class="px-3 py-2">{{ __('Site') }}</th><th class="px-3 py-2">{{ __('Domain') }}</th><th class="px-3 py-2">{{ __('Status') }}</th><th class="px-3 py-2">{{ __('Challenge') }}</th><th class="px-3 py-2">{{ __('Expires') }}</th></tr></thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white">
                            @foreach ($report['items'] as $item)
                                <tr @class(['bg-rose-50/40' => $item['severity'] === 'critical', 'bg-amber-50/30' => $item['severity'] === 'warning'])>
                                    <td class="px-3 py-2">@if ($item['href'])<a href="{{ $item['href'] }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ $item['site_name'] }}</a>@else{{ $item['site_name'] }}@endif</td>
                                    <td class="px-3 py-2 text-brand-moss">{{ $item['domain'] }}</td>
                                    <td class="px-3 py-2">{{ $item['status'] }}</td>
                                    <td class="px-3 py-2 text-brand-moss">{{ strtoupper($item['challenge']) }}</td>
                                    <td class="px-3 py-2 text-brand-moss">@if ($item['expires_at']){{ $item['expires_at']->format('Y-m-d') }} ({{ $item['days_left'] }}d)@else—@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <x-modal name="cert-inventory-renew">
        <div class="p-6">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Bulk renew certificates?') }}</h3>
            <p class="mt-2 text-sm text-brand-moss">{{ __('Queues renewal jobs for managed certificates that are failed or expiring within :days days on this server.', ['days' => $report['warning_days']]) }}</p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="closeRenewModal" class="rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold">{{ __('Cancel') }}</button>
                <button type="button" wire:click="queueBulkRenew" class="rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream">{{ __('Queue renewals') }}</button>
            </div>
        </div>
    </x-modal>
</x-server-workspace-layout>
