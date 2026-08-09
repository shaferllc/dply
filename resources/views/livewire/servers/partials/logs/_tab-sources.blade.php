@php
    $tonePalette = $tonePalette ?? [];
    $sourceRows = $report['source_rows'] ?? [];
    $opsReady = (bool) ($report['ops_ready'] ?? false);
    $isDeployer = (bool) ($report['is_deployer'] ?? false);
@endphp

<section class="border-b border-brand-ink/10">
    {{-- The "SOURCES" eyebrow over "Available sources" said the same thing twice,
         directly under a tab already labelled Sources. Row count moves to the
         head's pill. --}}
    <x-workspace-panel-head
        icon="heroicon-o-queue-list"
        :title="__('Available sources')"
        :note="__('Catalog filtered by installed services and sites on this server. Click a row to open it in the viewer.')"
        :count="count($sourceRows) ?: null"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <button
                type="button"
                wire:click="setLogsWorkspaceTab('viewer')"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-forest shadow-sm transition hover:bg-brand-sand/40"
            >
                {{ __('Open viewer') }}
                <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            </button>
        </x-slot:actions>
    </x-workspace-panel-head>

    @if ($sourceRows === [])
        <div class="px-5 py-6 text-center text-xs text-brand-moss sm:px-6">
            {{ __('No log sources configured for this server.') }}
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-xs">
                <thead class="bg-brand-sand/20 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-mist">
                    <tr>
                        <th scope="col" class="px-4 py-2">{{ __('Source') }}</th>
                        <th scope="col" class="px-4 py-2">{{ __('Group') }}</th>
                        <th scope="col" class="px-4 py-2">{{ __('Type') }}</th>
                        <th scope="col" class="px-4 py-2">{{ __('Access') }}</th>
                        <th scope="col" class="px-4 py-2 text-right">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/8 bg-white">
                    @foreach ($sourceRows as $row)
                        <tr wire:key="log-source-row-{{ $row['key'] }}" @class(['bg-brand-sage/5' => $row['active']])>
                            <td class="px-4 py-2">
                                <div class="font-medium text-brand-ink">{{ $row['label'] }}</div>
                                @if ($row['path'])
                                    <div class="mt-0.5 font-mono text-xs text-brand-mist">{{ $row['path'] }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-brand-moss">{{ $row['group_label'] }}</td>
                            <td class="px-4 py-2 font-mono text-brand-moss">{{ $row['type'] }}</td>
                            <td class="px-4 py-2">
                                @if ($row['active'])
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $tonePalette['emerald'] }}">{{ __('Active') }}</span>
                                @elseif ($row['ssh_required'] && (! $opsReady || $isDeployer))
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $tonePalette['amber'] }}">{{ __('SSH blocked') }}</span>
                                @else
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $tonePalette['mist'] }}">{{ __('Available') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                @if ($row['ssh_required'] && (! $opsReady || $isDeployer))
                                    <span class="text-xs text-brand-mist">{{ __('Unavailable') }}</span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="selectLogSourceFromCatalog('{{ $row['key'] }}')"
                                        class="text-xs font-semibold text-brand-forest hover:underline"
                                    >
                                        {{ $row['active'] ? __('Viewing') : __('Open') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
