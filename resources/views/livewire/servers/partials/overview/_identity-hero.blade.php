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

                    {{-- Installed runtime, incorporated into the identity line rather
                         than a standalone card: database / language / webserver / cache. --}}
                    @php
                        $hasRuntimeChips = ! $isDedicatedServiceRoleHost && (
                            $installedStack->database
                            || $installedStack->phpVersion
                            || $installedStack->webserver
                            || ($installedStack->cacheService && $installedStack->cacheService !== 'none')
                        );
                    @endphp
                    @if ($hasRuntimeChips)
                        <div class="mt-3 flex flex-wrap items-center gap-1.5">
                            @if ($installedStack->database)
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-0.5 text-xs font-medium text-brand-ink">
                                    {{ str($installedStack->database)->headline() }}@if ($installedStack->databaseVersion)<span class="ml-1 font-mono text-[11px] text-brand-moss">{{ $installedStack->databaseVersion }}</span>@endif
                                </span>
                            @endif
                            @if ($installedStack->phpVersion)
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-0.5 text-xs font-medium text-brand-ink">
                                    PHP <span class="ml-1 font-mono text-[11px] text-brand-moss">{{ $installedStack->phpVersion }}</span>
                                </span>
                            @endif
                            @if ($installedStack->webserver)
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-0.5 text-xs font-medium text-brand-ink">
                                    {{ str($installedStack->webserver)->headline() }}
                                </span>
                            @endif
                            @if ($installedStack->cacheService && $installedStack->cacheService !== 'none')
                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-0.5 text-xs font-medium text-brand-ink">
                                    {{ str($installedStack->cacheService)->headline() }}
                                </span>
                            @endif
                            @if ($installedStack->lowMemoryMode)
                                <span class="inline-flex items-center gap-1 rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800" title="{{ __('Provisioned in low-memory mode — substituted lighter services where possible.') }}">
                                    <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Low-memory mode') }}
                                </span>
                            @endif
                            @feature('workspace.services')
                                <a href="{{ route('servers.services', $server) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest transition hover:text-brand-sage hover:underline">
                                    {{ __('Services') }}
                                    <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                                </a>
                            @endfeature
                        </div>
                        @if ($installedStack->lowMemoryMode)
                            <p class="mt-2 rounded-xl border border-amber-200 bg-amber-50/60 px-3 py-2 text-xs leading-relaxed text-amber-900">
                                @if ($installedStackDiverges)
                                    {{ __('Low-memory mode: :memMb MB RAM is under the 1 GB threshold, so SQLite was installed instead of :requested. Re-provision on a 2 GB+ droplet for a full database server — see journey for details.', [
                                        'memMb' => $installedStack->totalMemoryMb ?: '<1024',
                                        'requested' => str($server->meta['database'] ?? 'a database server')->headline(),
                                    ]) }}
                                @else
                                    {{ __('Low-memory mode: :memMb MB RAM is under the 1 GB threshold, so lighter services were substituted. Re-provision on a 2 GB+ droplet for the full stack — see journey for details.', [
                                        'memMb' => $installedStack->totalMemoryMb ?: '<1024',
                                    ]) }}
                                @endif
                            </p>
                        @elseif ($installedStackDiverges)
                            <p class="mt-2 rounded-xl border border-amber-200 bg-amber-50/60 px-3 py-2 text-xs leading-relaxed text-amber-900">
                                {{ __('Wizard requested :requested but :installed was installed instead. See journey for context.', [
                                    'requested' => $server->meta['database'] ?? '—',
                                    'installed' => $installedStack->database ?? '—',
                                ]) }}
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
        @php
            $heroProvider = $server->provider->label();
            $heroRegion = $server->region ?: '—';
            $heroIp = $server->public_ip_address ?? $server->ip_address ?? '—';
            $heroSize = $server->size ?? '—';
            $heroStatus = ucfirst(str_replace('_', ' ', (string) ($server->status ?? '—')));
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
                    <dt class="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                    <dd class="text-right text-sm font-semibold text-brand-ink">{{ $heroStatus }}</dd>
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

    {{-- Merged: workspace summary tiles live in the same card as the identity,
         so the overview opens with a single header card instead of two. --}}
    @unless ($isDedicatedServiceRoleHost)
        <div class="border-t border-brand-ink/10 bg-brand-sand/25 p-6 sm:p-8">
            <div class="mb-3 flex items-center gap-2">
                <x-heroicon-o-squares-2x2 class="h-4 w-4 text-brand-mist" aria-hidden="true" />
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Workspace summary') }}</p>
            </div>
            @include('livewire.servers.partials.overview._summary-tiles-grid')
        </div>
    @endunless

    {{-- Merged: live system load (CPU / memory / disk) lives in the header card too. --}}
    <div class="border-t border-brand-ink/10 p-6 sm:p-8">
        @include('livewire.servers.partials.overview._live-metrics-body')
    </div>
</section>
