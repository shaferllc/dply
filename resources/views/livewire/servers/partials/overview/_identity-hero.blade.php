@php
    $healthStatus = $healthSummary['status'];
    $healthLabel = match ($healthStatus) {
        \App\Models\Server::HEALTH_REACHABLE => __('Reachable'),
        \App\Models\Server::HEALTH_UNREACHABLE => __('Needs attention'),
        default => __('No health check yet'),
    };
    $healthDot = $healthStatus === \App\Models\Server::HEALTH_REACHABLE
        ? 'bg-emerald-500'
        : ($healthStatus === \App\Models\Server::HEALTH_UNREACHABLE ? 'bg-rose-500' : 'bg-brand-gold');
@endphp

{{-- Hero: server identity + facts. --}}
<section class="dply-card overflow-hidden">
    <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
        <div class="lg:col-span-7">
            <div class="flex items-start gap-3">
                <x-icon-badge size="md">
                    <x-heroicon-o-server-stack class="h-6 w-6" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Server') }}</p>
                    <h1 class="mt-1 truncate text-xl font-semibold tracking-tight text-brand-ink">{{ $server->name }}</h1>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
                        <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-brand-ink/10 bg-white px-2 py-0.5">
                            <span class="h-1.5 w-1.5 rounded-full {{ $healthDot }}"></span>
                            {{ $healthLabel }}
                        </span>
                        <span class="inline-flex items-center gap-1 font-mono">
                            <span class="text-[10px] uppercase tracking-[0.16em] text-brand-mist">SSH</span>
                            <span class="break-all text-brand-ink">{{ $server->getSshConnectionString() }}</span>
                        </span>
                        @if ($healthSummary['last_checked_at'])
                            <span class="text-brand-mist" title="{{ __('Last health check') }}">
                                {{ __('Checked :ago', ['ago' => $healthSummary['last_checked_at']->diffForHumans()]) }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @php
            $heroProvider = $server->provider->label();
            $heroRegion = $server->region ?: '—';
            $heroIp = $server->ip_address ?? '—';
            $heroSize = $server->size ?? '—';
        @endphp
        <div class="lg:col-span-5">
            <dl class="divide-y divide-brand-ink/10 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                <div class="grid grid-cols-3 divide-x divide-brand-ink/10">
                    <div class="min-w-0 px-3 py-2.5 sm:px-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Provider') }}</dt>
                        <dd class="mt-1 truncate text-sm font-semibold text-brand-ink" title="{{ $heroProvider }}">{{ $heroProvider }}</dd>
                    </div>
                    <div class="min-w-0 px-3 py-2.5 sm:px-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Region') }}</dt>
                        <dd class="mt-1 truncate text-sm font-semibold text-brand-ink" title="{{ $heroRegion }}">{{ $heroRegion }}</dd>
                    </div>
                    <div class="min-w-0 px-3 py-2.5 sm:px-4">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Size') }}</dt>
                        <dd class="mt-1 truncate font-mono text-sm font-semibold text-brand-ink" title="{{ $heroSize }}">{{ $heroSize }}</dd>
                    </div>
                </div>
                <div class="flex items-baseline justify-between gap-4 px-3 py-2.5 sm:px-4">
                    <dt class="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('IP') }}</dt>
                    <dd class="select-all break-all text-right font-mono text-sm font-semibold text-brand-ink">{{ $heroIp }}</dd>
                </div>
                @if ($server->private_ip_address)
                    <div class="flex items-baseline justify-between gap-4 px-3 py-2.5 sm:px-4">
                        <dt class="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Private IP') }}</dt>
                        <dd class="select-all break-all text-right font-mono text-sm font-semibold text-brand-ink">{{ $server->private_ip_address }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>
</section>
