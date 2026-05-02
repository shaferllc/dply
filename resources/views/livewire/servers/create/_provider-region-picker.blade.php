{{--
  Slick region picker copied from the original Servers/Create page.
  Expects: $catalog (regions, region_label), $form (Livewire form object).
  Sets form.region via wire:click. Includes a search box and a "View map" modal.
--}}
@php
    $regionOptions = collect($catalog['regions'] ?? [])->values();
    $selectedRegionOption = $regionOptions->firstWhere('value', $form->region);
    $digitalOceanRegionMarkers = collect([
        ['value' => 'nyc1', 'label' => 'New York', 'top' => '34%', 'left' => '29%'],
        ['value' => 'nyc2', 'label' => 'New York', 'top' => '34%', 'left' => '29%'],
        ['value' => 'nyc3', 'label' => 'New York', 'top' => '34%', 'left' => '29%'],
        ['value' => 'tor1', 'label' => 'Toronto', 'top' => '29%', 'left' => '27%'],
        ['value' => 'sfo1', 'label' => 'San Francisco', 'top' => '35%', 'left' => '14%'],
        ['value' => 'sfo2', 'label' => 'San Francisco', 'top' => '35%', 'left' => '14%'],
        ['value' => 'sfo3', 'label' => 'San Francisco', 'top' => '35%', 'left' => '14%'],
        ['value' => 'ams2', 'label' => 'Amsterdam', 'top' => '28%', 'left' => '50%'],
        ['value' => 'ams3', 'label' => 'Amsterdam', 'top' => '28%', 'left' => '50%'],
        ['value' => 'lon1', 'label' => 'London', 'top' => '26%', 'left' => '47%'],
        ['value' => 'fra1', 'label' => 'Frankfurt', 'top' => '29%', 'left' => '53%'],
        ['value' => 'blr1', 'label' => 'Bangalore', 'top' => '47%', 'left' => '67%'],
        ['value' => 'sgp1', 'label' => 'Singapore', 'top' => '58%', 'left' => '76%'],
        ['value' => 'syd1', 'label' => 'Sydney', 'top' => '80%', 'left' => '86%'],
    ])->filter(fn (array $marker) => $regionOptions->contains(fn (array $region) => ($region['value'] ?? null) === $marker['value']))->values();
@endphp

<div>
    <x-input-label for="form_region" :value="$catalog['region_label'] ?? __('Region')" />
    <div
        x-data="{ open: false, search: '', mapOpen: false }"
        x-on:dply-region-selected.window="$wire.set('form.region', $event.detail.value); mapOpen = false"
        class="relative mt-1"
    >
        @if ($regionOptions->isEmpty())
            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-5 text-sm text-slate-500">
                {{ __('Select an account first to load regions.') }}
            </div>
        @else
            <button
                id="form_region"
                type="button"
                x-on:click="open = !open"
                x-on:keydown.escape.window="open = false"
                x-bind:aria-expanded="open.toString()"
                aria-haspopup="listbox"
                class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-left shadow-sm transition focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Selected region') }}</div>
                        @if ($selectedRegionOption)
                            <div class="mt-1 truncate text-sm font-semibold text-slate-900">{{ $selectedRegionOption['label'] }}</div>
                        @else
                            <div class="mt-1 text-sm text-slate-500">{{ __('Select region') }}</div>
                        @endif
                    </div>
                    <div class="shrink-0 pt-1 text-slate-400" x-bind:class="{ 'rotate-180': open }">
                        <x-heroicon-m-chevron-down class="h-5 w-5 transition-transform" aria-hidden="true" />
                    </div>
                </div>
            </button>

            <div
                x-cloak
                x-show="open"
                x-transition.origin.top
                x-on:click.outside="open = false"
                role="listbox"
                class="absolute z-20 mt-2 w-full rounded-2xl border border-slate-200 bg-white p-3 shadow-xl shadow-slate-200/80"
            >
                @if ($digitalOceanRegionMarkers->isNotEmpty())
                    <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Map') }}</div>
                                <div class="mt-1 text-sm text-slate-600">{{ __('Open the full map for easier geographic selection.') }}</div>
                            </div>
                            <button
                                type="button"
                                x-on:click="open = false; mapOpen = true; $nextTick(() => window.dispatchEvent(new Event('dply:region-map-open')))"
                                class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50"
                            >
                                {{ __('View map') }}
                            </button>
                        </div>
                    </div>
                @endif

                <div class="mt-3">
                    <input
                        x-model="search"
                        type="text"
                        class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20"
                        placeholder="{{ __('Search regions…') }}"
                    />
                </div>

                <div class="mt-3 max-h-56 space-y-2 overflow-y-auto overscroll-contain pr-1">
                    @foreach ($regionOptions as $regionOption)
                        <button
                            type="button"
                            role="option"
                            wire:click="$set('form.region', '{{ $regionOption['value'] }}')"
                            x-on:click="open = false"
                            x-show="'{{ Str::lower((string) ($regionOption['label'] ?? '')) }}'.includes(search.toLowerCase()) || '{{ Str::lower((string) ($regionOption['value'] ?? '')) }}'.includes(search.toLowerCase())"
                            aria-selected="{{ $form->region === $regionOption['value'] ? 'true' : 'false' }}"
                            class="w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 {{ $form->region === $regionOption['value'] ? 'border-sky-600 bg-sky-50 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' }}"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-slate-900">{{ $regionOption['label'] }}</div>
                                    <div class="mt-1 text-[11px] uppercase tracking-[0.16em] text-slate-500">{{ $regionOption['value'] }}</div>
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>

            @if ($digitalOceanRegionMarkers->isNotEmpty())
                <div
                    x-cloak
                    x-show="mapOpen"
                    x-transition.opacity
                    x-on:keydown.escape.window="mapOpen = false"
                    class="fixed inset-0 z-50 overflow-y-auto overscroll-y-contain"
                    role="dialog"
                    aria-modal="true"
                >
                    <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" x-on:click="mapOpen = false"></div>
                    <div class="flex min-h-full items-center justify-center p-4 sm:p-6">
                        <div class="relative w-full max-w-5xl overflow-hidden rounded-3xl border border-brand-ink/10 bg-white shadow-2xl">
                            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                                <div>
                                    <h3 class="text-lg font-semibold text-slate-900">{{ __('Region map') }}</h3>
                                    <p class="mt-1 text-sm text-slate-600">{{ __('Pick a region by location, or use the list on the right.') }}</p>
                                </div>
                                <button type="button" x-on:click="mapOpen = false" class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-50 hover:text-slate-700">
                                    <x-heroicon-m-x-mark class="h-5 w-5" aria-hidden="true" />
                                </button>
                            </div>

                            <div class="grid gap-6 px-6 py-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(280px,0.9fr)]">
                                <div class="rounded-3xl border border-slate-200 bg-[linear-gradient(180deg,#dbeafe_0%,#eff6ff_55%,#f8fafc_100%)] p-5">
                                    <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
                                        <div class="mb-3 text-sm font-medium text-slate-700">{{ __('Interactive world map') }}</div>
                                        <div
                                            data-region-map
                                            data-selected-region="{{ $form->region }}"
                                            data-region-points='@json($digitalOceanRegionMarkers)'
                                            class="h-[24rem] w-full overflow-hidden rounded-2xl border border-slate-200"
                                        ></div>
                                    </div>
                                </div>
                                <div class="rounded-3xl border border-slate-200 bg-slate-50/70 p-5">
                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('All regions') }}</div>
                                    <div class="mt-1 text-sm text-slate-600">{{ __('Pick any region — even ones not pinned on the map.') }}</div>
                                    <div class="mt-4 max-h-[28rem] space-y-2 overflow-y-auto pr-1">
                                        @foreach ($regionOptions as $regionOption)
                                            <button
                                                type="button"
                                                wire:click="$set('form.region', '{{ $regionOption['value'] }}')"
                                                x-on:click="mapOpen = false"
                                                class="w-full rounded-2xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 {{ $form->region === $regionOption['value'] ? 'border-sky-600 bg-sky-50 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' }}"
                                            >
                                                <div class="flex items-start justify-between gap-4">
                                                    <div class="min-w-0">
                                                        <div class="truncate text-sm font-semibold text-slate-900">{{ $regionOption['label'] }}</div>
                                                        <div class="mt-1 text-[11px] uppercase tracking-[0.16em] text-slate-500">{{ $regionOption['value'] }}</div>
                                                    </div>
                                                </div>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </div>
    <x-input-error :messages="$errors->get('form.region')" class="mt-1" />
</div>
