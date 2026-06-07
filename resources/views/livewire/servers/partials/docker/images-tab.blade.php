<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Images') }}</h2>
        <div class="flex flex-wrap items-center gap-2">
            @if (is_array($serviceActions['docker_image_prune'] ?? null))
                <button type="button" wire:click="confirmDockerImagePrune" class="inline-flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                    {{ $serviceActions['docker_image_prune']['label'] }}
                </button>
            @endif
            <button type="button" wire:click="loadImages" wire:loading.attr="disabled" wire:target="loadImages" class="inline-flex items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                <span wire:loading.remove wire:target="loadImages" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-4 w-4" aria-hidden="true" />
                    {{ __('Refresh') }}
                </span>
                <span wire:loading wire:target="loadImages" class="inline-flex items-center gap-1.5">
                    <x-spinner variant="forest" size="sm" />
                    {{ __('Refreshing…') }}
                </span>
            </button>
        </div>
    </div>

    <div class="border-b border-brand-ink/10 bg-white px-6 py-4 sm:px-7">
        <label for="pull-image-input" class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Pull image') }}</label>
        <div class="mt-2 flex flex-wrap gap-2">
            <input
                id="pull-image-input"
                type="text"
                wire:model="pullImageInput"
                placeholder="nginx:alpine"
                class="dply-input min-w-[12rem] flex-1 font-mono text-sm"
            />
            <button type="button" wire:click="confirmDockerImagePull" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-cream hover:bg-brand-forest">
                {{ __('Pull') }}
            </button>
        </div>
    </div>

    @if ($imagesLoading && $images === null)
        <div class="flex items-center justify-center gap-2 px-6 py-12 text-sm text-brand-moss">
            <x-spinner variant="forest" size="sm" />
            {{ __('Loading images…') }}
        </div>
    @elseif ($imagesError)
        <p class="px-6 py-8 text-sm text-rose-700 sm:px-7">{{ $imagesError }}</p>
    @elseif ($images === [] || $images === null)
        <p class="px-6 py-8 text-sm text-brand-moss sm:px-7">{{ __('No images reported.') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-3 sm:px-6">{{ __('Repository') }}</th>
                        <th class="px-4 py-3">{{ __('Tag') }}</th>
                        <th class="px-4 py-3">{{ __('ID') }}</th>
                        <th class="px-4 py-3">{{ __('Size') }}</th>
                        <th class="px-4 py-3">{{ __('Created') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
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
                            <td class="px-4 py-3 font-mono text-xs text-brand-ink sm:px-6">{{ $row['repository'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-brand-moss">{{ $row['tag'] }}</td>
                            <td class="px-4 py-3 font-mono text-[11px] text-brand-moss">{{ strlen($row['id']) > 14 ? substr($row['id'], 0, 14) : $row['id'] }}</td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['size'] }}</td>
                            <td class="px-4 py-3 text-brand-moss">{{ $row['created'] }}</td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" wire:click="confirmDockerImageAction('docker_image_rm', @js($imageRef))" class="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-800 hover:bg-rose-100">{{ __('Remove') }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
