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

<div aria-labelledby="manage-tools-title">
    <h2 id="manage-tools-title" class="sr-only">{{ __('Tools') }}</h2>

    @if ($report)
        @include('livewire.servers.partials.manage.tools.header')

        <div class="border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Tools sections')" class="!mb-0 border-0 bg-transparent p-0 shadow-none">
                <x-server-workspace-tab
                    id="manage-tools-tab-catalog"
                    :active="$toolsPanel === 'tools'"
                    wire:click="setToolsPanel('tools')"
                    icon="heroicon-o-wrench-screwdriver"
                >
                    {{ __('Tools') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="manage-tools-tab-runtimes"
                    :active="$toolsPanel === 'runtimes'"
                    wire:click="setToolsPanel('runtimes')"
                    icon="heroicon-o-cpu-chip"
                >
                    {{ __('Runtimes') }}
                    @if (($summary['runtime_versions'] ?? 0) > 0)
                        <span class="ml-1 rounded-full bg-brand-sand/60 px-1.5 py-0.5 font-mono text-[10px] tabular-nums text-brand-moss">
                            {{ $summary['runtime_versions'] }}
                        </span>
                    @endif
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        @if ($toolsPanel === 'tools')
            @include('livewire.servers.partials.manage.tools.tools-list')
        @elseif ($toolsPanel === 'runtimes')
            @include('livewire.servers.partials.manage.tools.runtimes')
        @endif
    @else
        <p class="px-5 py-6 text-sm text-brand-moss sm:px-6">{{ __('Tool inventory appears after the first successful probe.') }}</p>
    @endif
</div>
