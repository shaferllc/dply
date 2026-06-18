{{-- Stack summary (app servers only — dedicated cache/db boxes use their tile packs). --}}
@if (! $isDedicatedServiceRoleHost)
<section class="dply-card overflow-hidden">
    <div class="px-6 pt-5 pb-4 sm:px-7">
        <div class="flex items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-cpu-chip class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Installed runtime') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Database engine, language runtime, webserver, cache.') }}</p>
            </div>
            @feature('workspace.services')
                <a href="{{ route('servers.services', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                    <x-heroicon-m-cpu-chip class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Open Services') }}
                </a>
            @endfeature
        </div>
    </div>
    <div class="p-6 sm:p-7">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            @if ($installedStack->database)
                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-1 font-medium text-brand-ink">
                    {{ str($installedStack->database)->headline() }}@if ($installedStack->databaseVersion)<span class="ml-1 font-mono text-xs text-brand-moss">{{ $installedStack->databaseVersion }}</span>@endif
                </span>
            @endif
            @if ($installedStack->phpVersion)
                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-1 font-medium text-brand-ink">
                    PHP <span class="ml-1 font-mono text-xs text-brand-moss">{{ $installedStack->phpVersion }}</span>
                </span>
            @endif
            @if ($installedStack->webserver)
                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-1 font-medium text-brand-ink">
                    {{ str($installedStack->webserver)->headline() }}
                </span>
            @endif
            @if ($installedStack->cacheService && $installedStack->cacheService !== 'none')
                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-2 py-1 font-medium text-brand-ink">
                    {{ str($installedStack->cacheService)->headline() }}
                </span>
            @endif
            @if ($installedStack->lowMemoryMode)
                <span class="inline-flex items-center gap-1 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800" title="{{ __('Provisioned in low-memory mode — substituted lighter services where possible.') }}">
                    <x-heroicon-m-exclamation-triangle class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Low-memory mode') }}
                </span>
            @endif
        </div>
        @if ($installedStack->lowMemoryMode)
            <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50/60 px-3 py-2 text-xs leading-relaxed text-amber-900">
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
            <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50/60 px-3 py-2 text-xs leading-relaxed text-amber-900">
                {{ __('Wizard requested :requested but :installed was installed instead. See journey for context.', [
                    'requested' => $server->meta['database'] ?? '—',
                    'installed' => $installedStack->database ?? '—',
                ]) }}
            </p>
        @endif
    </div>
</section>
@endif
