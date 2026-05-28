@php
    $tonePalette = ['amber' => 'bg-amber-50 text-amber-900 ring-amber-200', 'rose' => 'bg-rose-50 text-rose-700 ring-rose-200', 'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'];
    $overallTone = match ($report['overall']) { 'critical' => $tonePalette['rose'], 'warning' => $tonePalette['amber'], default => $tonePalette['emerald'] };
    $opsReady = $server->isReady() && $server->ip_address && $server->ssh_private_key;
    $isDeployer = auth()->user()->currentOrganization()?->userIsDeployer(auth()->user()) ?? false;
@endphp

<x-server-workspace-layout :server="$server" active="security-digest" :title="__('Security digest')" :description="__('SSH auth failure volume and fail2ban status — lightweight read-only digest over root SSH.')">
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])
    <x-explainer><p>{{ __('Counts Failed password / Invalid user lines in auth.log and reads fail2ban jail status. For full log tailing, use Logs.') }}</p></x-explainer>

    <div class="space-y-6">
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $overallTone }}"><x-heroicon-o-shield-exclamation class="h-5 w-5" /></span>
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Auth & fail2ban') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                @if ($report['scan']['checked_at']){{ __('Last scan :time', ['time' => $report['scan']['checked_at']->diffForHumans()]) }}@else{{ __('No scan yet') }}@endif
                            </p>
                        </div>
                    </div>
                    @if ($opsReady && ! $isDeployer)
                        <button type="button" wire:click="refreshSecurityDigestScan" wire:loading.attr="disabled" wire:target="refreshSecurityDigestScan" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <span wire:loading.remove wire:target="refreshSecurityDigestScan">{{ __('Refresh digest') }}</span>
                            <span wire:loading wire:target="refreshSecurityDigestScan">{{ __('Scanning…') }}</span>
                        </button>
                    @endif
                </div>
            </div>
            @if ($report['alert_count'] > 0)
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($report['alerts'] as $alert)
                        <li class="flex flex-wrap justify-between gap-3 px-6 py-4 sm:px-7">
                            <div><p class="text-sm font-semibold text-brand-ink">{{ $alert['title'] }}</p><p class="text-sm text-brand-moss">{{ $alert['message'] }}</p></div>
                            @if ($alert['href'])<a href="{{ $alert['href'] }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ $alert['link_label'] }}</a>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <dl class="grid grid-cols-2 gap-4 px-6 py-5 text-sm sm:px-7">
                <div><dt class="text-xs font-medium uppercase text-brand-mist">{{ __('auth.log failures') }}</dt><dd class="mt-1 text-2xl font-semibold text-brand-ink">{{ $report['auth']['failed_lines'] ?? '—' }}</dd></div>
                <div><dt class="text-xs font-medium uppercase text-brand-mist">{{ __('fail2ban') }}</dt><dd class="mt-1 text-lg font-semibold text-brand-ink">{{ $report['fail2ban']['active'] ?? '—' }}</dd></div>
            </dl>
            @if (count($report['fail2ban']['jails']) > 0)
                <p class="border-t border-brand-ink/10 px-6 py-3 text-xs text-brand-moss sm:px-7">{{ __('Jails') }}: {{ implode(', ', $report['fail2ban']['jails']) }}</p>
            @endif
        </section>
    </div>
</x-server-workspace-layout>
