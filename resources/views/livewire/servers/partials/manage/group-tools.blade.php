@php
    $report = $toolsReport ?? null;
    $meta = $server->meta ?? [];
    $manageMiseRuntimes = is_array($meta['manage_mise_runtimes'] ?? null) ? $meta['manage_mise_runtimes'] : [];
    $manageSystemRuntimes = is_array($meta['manage_system_runtimes'] ?? null) ? $meta['manage_system_runtimes'] : [];
    $checkedAt = $report['checked_at'] ?? null;
    $miseRuntimesProbed = (bool) ($report['mise_runtimes_probed'] ?? false);
    $toolsPanel = $toolsPanel ?? 'tools';

    $tonePalette = [
        'forest' => 'bg-brand-sage/18 text-brand-forest ring-brand-sage/35',
        'sky' => 'bg-sky-50 text-sky-900 ring-sky-200/90',
        'mist' => 'bg-brand-sand/55 text-brand-moss ring-brand-ink/12',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];

    $summary = $report['summary'] ?? [];
    $catalogRows = $report['catalog_rows'] ?? [];
    $heroTool = $report['hero_tool'] ?? null;

    $overall = $report['overall'] ?? 'ready';

    $statusTone = static function (string $tone) use ($tonePalette): string {
        return $tonePalette[$tone] ?? $tonePalette['mist'];
    };

    $statusBadgeDot = static function (string $tone): string {
        return match ($tone) {
            'forest' => 'bg-brand-forest',
            'sky' => 'bg-sky-600',
            'mist' => 'bg-brand-mist',
            'amber' => 'bg-amber-500',
            default => 'bg-brand-mist',
        };
    };

    $runtimeCatalog = is_array($report['mise_runtime_catalog'] ?? null) && ($report['mise_runtime_catalog'] ?? []) !== []
        ? $report['mise_runtime_catalog']
        : config('server_manage.mise_runtimes', []);

    $misePresent = (bool) ($heroTool['present'] ?? false);
    $miseVersion = $heroTool['version'] ?? null;
    $miseAction = is_array($heroTool['action'] ?? null) ? $heroTool['action'] : null;
    $misePruneAction = is_array($serviceActions['mise_prune'] ?? null) ? $serviceActions['mise_prune'] : null;
    $miseReshimAction = is_array($serviceActions['mise_reshim'] ?? null) ? $serviceActions['mise_reshim'] : null;
    $activeMiseRuntimeOps = is_array($activeMiseRuntimeOps ?? null) ? $activeMiseRuntimeOps : [];
    $activeToolActionOps = is_array($activeToolActionOps ?? null) ? $activeToolActionOps : [];
    $pendingToolActionKey = is_string($pendingToolActionKey ?? null) ? $pendingToolActionKey : null;
    $miseReprobePending = (bool) ($miseReprobePending ?? false);

    $toolActionIsActive = static function (?string $key) use ($activeToolActionOps, $pendingToolActionKey): bool {
        if ($key === null || $key === '') {
            return false;
        }

        if ($pendingToolActionKey === $key) {
            return true;
        }

        $op = $activeToolActionOps[$key] ?? null;

        return is_array($op)
            && in_array($op['status'] ?? '', ['queued', 'running'], true);
    };
@endphp

<section class="space-y-4" aria-labelledby="manage-tools-title">
    <h2 id="manage-tools-title" class="sr-only">{{ __('Tools') }}</h2>

    <p class="text-sm text-brand-moss">
        {{ __('Installed CLIs and version managers from the inventory probe — install, upgrade, or repair from here.') }}
    </p>

    @if ($report)
        @include('livewire.servers.partials.manage.tools.header')

        @include('livewire.servers.partials.manage.tools.panel-tabs')

        @if ($toolsPanel === 'tools')
            @include('livewire.servers.partials.manage.tools.tools-list')
        @elseif ($toolsPanel === 'runtimes')
            @include('livewire.servers.partials.manage.tools.runtimes')
        @endif
    @else
        <p class="text-sm text-brand-moss">{{ __('Tool inventory appears after the first successful probe.') }}</p>
    @endif
</section>
