{{-- 5 click-through stat tiles. Rendered inside the identity-hero card footer
     and by _workspace-summary-tiles. Relies on the parent-scope vars:
     $healthValue / $healthMeta, $siteCount / $deployingCount, $databaseSummary,
     $installedStack, $latestDeployment, $backgroundSummary, $isWorkerRoleHost.

     The five tiles used to be written out longhand, identical apart from their
     href / label / value / meta — and again, verbatim, in
     _workspace-summary-tiles. One loop over one array now feeds both.

     Each tile also carried an always-rendered "Open Monitor →" line at
     opacity-0 that only appeared on hover: invisible, but occupying a fifth of
     every tile's height at all times. The whole tile is a link and already
     lifts its border and shadow on hover, so the line is gone. --}}
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

    if ($installedStack->database) {
        $databaseMeta = str($installedStack->database)->headline()
            .($installedStack->databaseVersion ? ' · '.$installedStack->databaseVersion : '');
    } else {
        $databaseMeta = __('No engine recorded');
    }

    // Background meta keeps its severity colour — failures outrank pauses.
    if ($backgroundSummary['failed_backups_7d'] > 0) {
        $backgroundMeta = trans_choice('{1} :count failed backup (7d)|[2,*] :count failed backups (7d)', $backgroundSummary['failed_backups_7d'], ['count' => $backgroundSummary['failed_backups_7d']]);
        $backgroundMetaClass = 'font-semibold text-red-700';
    } elseif ($backgroundSummary['paused_schedules'] > 0) {
        $backgroundMeta = trans_choice('{1} :count paused schedule|[2,*] :count paused schedules', $backgroundSummary['paused_schedules'], ['count' => $backgroundSummary['paused_schedules']]);
        $backgroundMetaClass = 'text-amber-700';
    } elseif ($backgroundSummary['active_schedules'] > 0) {
        $backgroundMeta = trans_choice('{1} :count active schedule|[2,*] :count active schedules', $backgroundSummary['active_schedules'], ['count' => $backgroundSummary['active_schedules']]);
        $backgroundMetaClass = 'text-brand-moss';
    } else {
        $backgroundMeta = __('No schedules yet');
        $backgroundMetaClass = 'text-brand-moss';
    }

    $summaryTiles = [
        ['label' => __('Health'), 'href' => route('servers.monitor', $server), 'value' => $healthValue, 'meta' => $healthMeta],
        ['label' => __('Sites'), 'href' => route('servers.sites', $server), 'value' => $siteCount, 'mono' => true, 'meta' => $deployingMeta],
    ];

    if (! $isWorkerRoleHost) {
        $summaryTiles[] = ['label' => __('Databases'), 'href' => route('servers.databases', $server), 'value' => $databaseSummary['count'], 'mono' => true, 'meta' => $databaseMeta];
    }

    $summaryTiles[] = ['label' => __('Latest deploy'), 'href' => route('servers.deploys', $server), 'value' => $latestDeployValue, 'meta' => $latestDeployMeta];
    $summaryTiles[] = [
        'label' => __('Background'),
        'href' => route($isWorkerRoleHost ? 'servers.workers' : 'servers.backups', $server),
        'value' => $backgroundSummary['active_workers'],
        'mono' => true,
        'unit' => __('workers'),
        'meta' => $backgroundMeta,
        'meta_class' => $backgroundMetaClass,
    ];
@endphp

<div class="grid gap-px overflow-hidden rounded-2xl border border-brand-ink/10 bg-brand-ink/[0.07] shadow-sm sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
    @foreach ($summaryTiles as $tile)
        <a
            href="{{ $tile['href'] }}"
            wire:navigate
            class="block bg-white px-3 py-2 transition-colors hover:bg-brand-sand/[0.15] sm:px-4"
        >
            <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $tile['label'] }}</p>
            <p class="mt-0.5 flex items-baseline gap-1.5">
                <span @class([
                    'truncate font-semibold text-brand-ink',
                    'font-mono text-base tabular-nums' => $tile['mono'] ?? false,
                    'text-sm' => ! ($tile['mono'] ?? false),
                ])>{{ $tile['value'] }}</span>
                @if (! empty($tile['unit']))
                    <span class="text-xs text-brand-moss">{{ $tile['unit'] }}</span>
                @endif
            </p>
            <p class="mt-0.5 truncate text-xs {{ $tile['meta_class'] ?? 'text-brand-moss' }}">{{ $tile['meta'] }}</p>
        </a>
    @endforeach
</div>
