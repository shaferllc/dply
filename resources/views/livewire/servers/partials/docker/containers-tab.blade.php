<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Containers') }}</h2>
        <button type="button" wire:click="loadContainers" wire:loading.attr="disabled" wire:target="loadContainers" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
            <span wire:loading.remove wire:target="loadContainers" class="inline-flex items-center gap-1.5">
                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Refresh') }}
            </span>
            <span wire:loading wire:target="loadContainers" class="inline-flex items-center gap-1.5">
                <x-spinner variant="forest" size="sm" />
                {{ __('Refreshing…') }}
            </span>
        </button>
    </div>

    @if ($containersLoading && $containers === null)
        <div class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-brand-moss">
            <x-spinner variant="forest" size="sm" />
            {{ __('Loading containers…') }}
        </div>
    @elseif ($containersError)
        <p class="px-6 py-8 text-sm text-rose-700 sm:px-7">{{ $containersError }}</p>
    @elseif ($containers === [] || $containers === null)
        <p class="px-6 py-8 text-sm text-brand-moss sm:px-7">{{ __('No containers reported.') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3 sm:px-6">{{ __('Name') }}</th>
                        <th class="px-4 py-3">{{ __('Image') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3">{{ __('Ports') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($containers as $row)
                        @php
                            $running = str_contains(strtolower($row['state'] ?? ''), 'running')
                                || str_contains(strtolower($row['status'] ?? ''), 'up ');
                            $ref = $row['id'];
                            $name = $row['name'];
                            $imageRef = $row['image'];
                        @endphp
                        <tr wire:key="docker-container-{{ $ref }}">
                            <td class="px-4 py-3 font-mono text-xs text-brand-ink sm:px-6">{{ $name }}</td>
                            <td class="max-w-[10rem] truncate px-4 py-3 font-mono text-xs text-brand-moss" title="{{ $imageRef }}">{{ $imageRef }}</td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['status'] }}</td>
                            <td class="max-w-[8rem] truncate px-4 py-3 font-mono text-[11px] text-brand-moss" title="{{ $row['ports'] ?? '' }}">{{ $row['ports'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex flex-wrap justify-end gap-1.5">
                                    <button type="button" wire:click="openContainerLogs(@js($ref), @js($name))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Logs') }}</button>
                                    <button type="button" wire:click="openContainerInspect(@js($ref), @js($name))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Inspect') }}</button>
                                    @if (! $running)
                                        <button type="button" wire:click="confirmDockerContainerAction('docker_container_start', @js($ref))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Start') }}</button>
                                    @else
                                        <button type="button" wire:click="confirmDockerContainerAction('docker_container_stop', @js($ref))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Stop') }}</button>
                                        <button type="button" wire:click="confirmDockerContainerAction('docker_container_restart', @js($ref))" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Restart') }}</button>
                                    @endif
                                    <button type="button" wire:click="confirmDockerContainerAction('docker_container_rm', @js($ref))" class="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-800 hover:bg-rose-100">{{ __('Remove') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<p class="mt-3 text-xs text-brand-moss">
    {{ __('Interactive exec is not run from this page. Copy from Inspect or use Run → Marketplace recipes for shell access.') }}
</p>
