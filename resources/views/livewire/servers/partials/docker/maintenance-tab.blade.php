<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Disk usage') }}</h2>
        <button type="button" wire:click="loadSystemDiskUsage" wire:loading.attr="disabled" wire:target="loadSystemDiskUsage" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
            <span wire:loading.remove wire:target="loadSystemDiskUsage" class="inline-flex items-center gap-1.5">
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Refresh') }}
            </span>
            <span wire:loading wire:target="loadSystemDiskUsage" class="inline-flex items-center gap-1.5">
                <x-spinner variant="forest" size="sm" />
                {{ __('Refreshing…') }}
            </span>
        </button>
    </div>

    @if ($systemDfLoading && $systemDf === null)
        <div class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-brand-moss">
            <x-spinner variant="forest" size="sm" />
            {{ __('Loading disk usage…') }}
        </div>
    @elseif ($systemDfError)
        <p class="px-6 py-8 text-sm text-rose-700 sm:px-7">{{ $systemDfError }}</p>
    @elseif ($systemDf === [] || $systemDf === null)
        <p class="px-6 py-8 text-sm text-brand-moss sm:px-7">{{ __('No disk usage data yet. Click Refresh.') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3 sm:px-6">{{ __('Type') }}</th>
                        <th class="px-4 py-3">{{ __('Total') }}</th>
                        <th class="px-4 py-3">{{ __('Active') }}</th>
                        <th class="px-4 py-3">{{ __('Size') }}</th>
                        <th class="px-4 py-3">{{ __('Reclaimable') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($systemDf as $row)
                        <tr wire:key="docker-df-{{ $row['type'] }}">
                            <td class="px-4 py-3 font-medium text-brand-ink sm:px-6">{{ $row['type'] }}</td>
                            <td class="px-4 py-3 tabular-nums text-brand-moss">{{ $row['total'] }}</td>
                            <td class="px-4 py-3 tabular-nums text-brand-moss">{{ $row['active'] }}</td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['size'] }}</td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['reclaimable'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<section class="mt-6 dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 ring-1 ring-amber-200">
            <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Cleanup') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Prune & cleanup') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Destructive actions run over SSH and stream output in the banner above.') }}</p>
        </div>
    </div>
    <div class="flex flex-wrap gap-3 px-6 py-5 sm:px-7">
        @if (is_array($serviceActions['docker_image_prune'] ?? null))
            <button type="button" wire:click="confirmDockerImagePrune" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                {{ $serviceActions['docker_image_prune']['label'] }}
            </button>
        @endif
        @if (is_array($serviceActions['docker_volume_prune'] ?? null))
            <button type="button" wire:click="confirmDockerVolumePrune" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                {{ $serviceActions['docker_volume_prune']['label'] }}
            </button>
        @endif
        @if (is_array($serviceActions['docker_system_prune'] ?? null))
            <button type="button" wire:click="confirmDockerSystemPrune" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-xs font-semibold text-rose-800 hover:bg-rose-100">
                {{ $serviceActions['docker_system_prune']['label'] }}
            </button>
        @endif
    </div>
</section>
