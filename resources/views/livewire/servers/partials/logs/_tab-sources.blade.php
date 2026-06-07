@php
    $tonePalette = $tonePalette ?? [];
    $sourceRows = $report['source_rows'] ?? [];
    $opsReady = (bool) ($report['ops_ready'] ?? false);
    $isDeployer = (bool) ($report['is_deployer'] ?? false);
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <div class="flex items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-queue-list class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Sources') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Available sources') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Catalog filtered by installed services and sites on this server. Click a row to open it in the viewer.') }}</p>
            </div>
        </div>
        <button
            type="button"
            wire:click="setLogsWorkspaceTab('viewer')"
            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-forest shadow-sm transition hover:bg-brand-sand/40"
        >
            {{ __('Open viewer') }}
            <x-heroicon-o-arrow-right class="h-4 w-4" aria-hidden="true" />
        </button>
    </div>

    @if ($sourceRows === [])
        <div class="px-6 py-10 text-center text-sm text-brand-moss sm:px-7">
            {{ __('No log sources configured for this server.') }}
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/20 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">
                    <tr>
                        <th scope="col" class="px-6 py-3">{{ __('Source') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Group') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('Type') }}</th>
                        <th scope="col" class="px-6 py-3">{{ __('Access') }}</th>
                        <th scope="col" class="px-6 py-3 text-right">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/8 bg-white">
                    @foreach ($sourceRows as $row)
                        <tr wire:key="log-source-row-{{ $row['key'] }}" @class(['bg-brand-sage/5' => $row['active']])>
                            <td class="px-6 py-3.5">
                                <div class="font-medium text-brand-ink">{{ $row['label'] }}</div>
                                @if ($row['path'])
                                    <div class="mt-0.5 font-mono text-[11px] text-brand-mist">{{ $row['path'] }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-brand-moss">{{ $row['group_label'] }}</td>
                            <td class="px-4 py-3.5 font-mono text-xs text-brand-moss">{{ $row['type'] }}</td>
                            <td class="px-6 py-3.5">
                                @if ($row['active'])
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $tonePalette['emerald'] }}">{{ __('Active') }}</span>
                                @elseif ($row['ssh_required'] && (! $opsReady || $isDeployer))
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $tonePalette['amber'] }}">{{ __('SSH blocked') }}</span>
                                @else
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $tonePalette['mist'] }}">{{ __('Available') }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-3.5 text-right">
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
