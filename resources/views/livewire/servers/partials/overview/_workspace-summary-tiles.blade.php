{{-- 5 click-through stat tiles, wrapped in a family section card.
     $healthValue / $healthMeta are computed by the parent view (they're
     also consumed by the database tile pack), so this partial relies on
     them being in scope rather than recomputing. --}}
@php
    $deployingMeta = $deployingCount > 0
        ? trans_choice('{1} :count site deploying|[2,*] :count sites deploying', $deployingCount, ['count' => $deployingCount])
        : trans_choice('{0} No sites yet|{1} 1 site|[2,*] :count sites', $siteCount, ['count' => $siteCount]);
    $latestDeployValue = $latestDeployment?->status
        ? str($latestDeployment->status)->headline()
        : __('None yet');
    $latestDeployMeta = $latestDeployment?->site
        ? __(':site · :time', [
            'site' => $latestDeployment->site->name,
            'time' => ($latestDeployment->finished_at ?? $latestDeployment->created_at)?->diffForHumans() ?? __('just now'),
        ])
        : __('No deploys yet');
@endphp
@if (! $isDedicatedServiceRoleHost)
<section class="dply-card overflow-hidden">
    <div class="px-6 pt-5 pb-4 sm:px-7">
        <div class="flex items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-squares-2x2 class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Workspace summary') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Each tile drops you onto its full workspace page.') }}</p>
            </div>
        </div>
    </div>
    <div class="grid gap-3 p-6 sm:grid-cols-2 sm:p-7 lg:grid-cols-3 xl:grid-cols-5">
        <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Health') }}</p>
            <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $healthValue }}</p>
            <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $healthMeta }}</p>
            <p class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100">
                {{ __('Open Monitor') }}
                <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
            </p>
        </a>

        <a href="{{ route('servers.sites', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Sites') }}</p>
            <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $siteCount }}</p>
            <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $deployingMeta }}</p>
            <p class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100">
                {{ __('Open Sites') }}
                <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
            </p>
        </a>

        @if (! $isWorkerRoleHost)
        <a href="{{ route('servers.databases', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Databases') }}</p>
            <p class="mt-1 font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $databaseSummary['count'] }}</p>
            <p class="mt-0.5 truncate text-[11px] text-brand-moss">
                @if ($installedStack->database)
                    {{ str($installedStack->database)->headline() }}@if ($installedStack->databaseVersion) · {{ $installedStack->databaseVersion }}@endif
                @else
                    {{ __('No engine recorded') }}
                @endif
            </p>
            <p class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100">
                {{ __('Open Databases') }}
                <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
            </p>
        </a>
        @endif

        <a href="{{ route('servers.deploys', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Latest deploy') }}</p>
            <p class="mt-1 truncate text-base font-semibold text-brand-ink">{{ $latestDeployValue }}</p>
            <p class="mt-0.5 truncate text-[11px] text-brand-moss">{{ $latestDeployMeta }}</p>
            <p class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100">
                {{ __('Open Deploys') }}
                <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
            </p>
        </a>

        <a href="{{ route($isWorkerRoleHost ? 'servers.workers' : 'servers.backups', $server) }}" wire:navigate class="group block rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm transition hover:border-brand-sage/30 hover:shadow-md">
            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Background') }}</p>
            <p class="mt-1 flex items-baseline gap-1.5">
                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $backgroundSummary['active_workers'] }}</span>
                <span class="text-[11px] text-brand-moss">{{ __('workers') }}</span>
            </p>
            <p class="mt-0.5 truncate text-[11px]">
                @if ($backgroundSummary['failed_backups_7d'] > 0)
                    <span class="font-semibold text-red-700">{{ trans_choice('{1} :count failed backup (7d)|[2,*] :count failed backups (7d)', $backgroundSummary['failed_backups_7d'], ['count' => $backgroundSummary['failed_backups_7d']]) }}</span>
                @elseif ($backgroundSummary['paused_schedules'] > 0)
                    <span class="text-amber-700">{{ trans_choice('{1} :count paused schedule|[2,*] :count paused schedules', $backgroundSummary['paused_schedules'], ['count' => $backgroundSummary['paused_schedules']]) }}</span>
                @elseif ($backgroundSummary['active_schedules'] > 0)
                    <span class="text-brand-moss">{{ trans_choice('{1} :count active schedule|[2,*] :count active schedules', $backgroundSummary['active_schedules'], ['count' => $backgroundSummary['active_schedules']]) }}</span>
                @else
                    <span class="text-brand-moss">{{ __('No schedules yet') }}</span>
                @endif
            </p>
            <p class="mt-2 inline-flex items-center gap-1 text-[10px] font-semibold text-brand-sage opacity-0 transition group-hover:opacity-100">
                {{ $isWorkerRoleHost ? __('Open Workers') : __('Open Backups') }}
                <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
            </p>
        </a>
    </div>
</section>
@endif
