<div class="border-b border-brand-ink/10">
    @include('livewire.servers.partials.docker._list-head', [
        'icon' => 'heroicon-o-photo',
        'title' => __('Images'),
        'target' => 'loadImages',
        'rows' => $images,
        'note' => __('Images stored on this engine. Pull by reference below; prune clears dangling layers.'),
        'actions' => is_array($serviceActions['docker_image_prune'] ?? null)
            ? new \Illuminate\Support\HtmlString(view('livewire.servers.partials.docker._prune-action', [
                'label' => $serviceActions['docker_image_prune']['label'],
            ])->render())
            : null,
    ])

    {{-- Pull row: a labelled field + its action on one line, rather than a
         stacked uppercase caption over a full-width input. --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-white px-4 py-2.5 sm:px-5">
        <label for="pull-image-input" class="shrink-0 text-[11px] font-semibold text-brand-moss">{{ __('Pull image') }}</label>
        <input
            id="pull-image-input"
            type="text"
            wire:model="pullImageInput"
            placeholder="nginx:alpine"
            class="dply-input h-8 min-w-[12rem] flex-1 font-mono text-xs"
        />
        <button type="button" wire:click="confirmDockerImagePull" class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-3 text-[11px] font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest">
            <x-heroicon-m-arrow-down-tray class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ __('Pull') }}
        </button>
    </div>

    @if ($imagesLoading || $imagesError || $images === [] || $images === null)
        @include('livewire.servers.partials.docker._list-state', [
            'loading' => $imagesLoading,
            'error' => $imagesError,
            'rows' => $images,
            'icon' => 'heroicon-o-photo',
            'errorTitle' => __('Could not list images'),
            'emptyTitle' => __('No images reported'),
            'emptyDescription' => __('This engine has no images cached yet. Pull one above, or deploy a Docker site to this server.'),
            'columns' => [24, 14, 16, 12, 16],
        ])
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-3 py-2 sm:px-5">{{ __('Repository') }}</th>
                        <th class="px-3 py-2">{{ __('Tag') }}</th>
                        <th class="px-3 py-2">{{ __('ID') }}</th>
                        <th class="px-3 py-2">{{ __('Size') }}</th>
                        <th class="px-3 py-2">{{ __('Created') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 bg-white">
                    @foreach ($images as $row)
                        @php
                            $imageRef = ($row['repository'] ?? '') === '<none>'
                                ? $row['id']
                                : ($row['repository'].(($row['tag'] ?? '') !== '' && ($row['tag'] ?? '') !== '<none>' ? ':'.$row['tag'] : ''));
                        @endphp
                        <tr wire:key="docker-image-{{ $row['id'] }}">
                            <td class="px-3 py-2 font-mono text-xs text-brand-ink sm:px-5">{{ $row['repository'] }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-brand-moss">{{ $row['tag'] }}</td>
                            <td class="px-3 py-2 font-mono text-[11px] text-brand-moss">{{ strlen($row['id']) > 14 ? substr($row['id'], 0, 14) : $row['id'] }}</td>
                            <td class="px-3 py-2 text-brand-moss">{{ $row['size'] }}</td>
                            <td class="px-3 py-2 text-brand-moss">{{ $row['created'] }}</td>
                            <td class="px-3 py-2 text-right">
                                <button type="button" wire:click="confirmDockerImageAction('docker_image_rm', @js($imageRef))" class="inline-flex h-6 items-center rounded-md border border-rose-200 bg-rose-50 px-2 text-[11px] font-semibold text-rose-800 transition hover:bg-rose-100">{{ __('Remove') }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
