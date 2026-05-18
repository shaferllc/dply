{{--
  K8s create-new region picker. Same chrome as _provider-region-picker but
  bound to form.do_kubernetes_new_region and without the interactive map
  panel (map is overkill for a one-step picker that's already inside a
  Kubernetes provisioning sub-form).

  Required vars:
    - $regions  : list<array{value: string, label: string}>
    - $form     : ServerCreateForm
--}}
@php
    $regionOptions = collect($regions ?? [])->values();
    $selectedRegionOption = $regionOptions->firstWhere('value', $form->do_kubernetes_new_region);
@endphp

<div>
    <x-input-label for="form_do_kubernetes_new_region" :value="__('Region')" />
    <div x-data="{ open: false, search: '' }" class="relative mt-1">
        @if ($regionOptions->isEmpty())
            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-5 text-sm text-slate-500">
                {{ __('Select an account first to load regions.') }}
            </div>
        @else
            <button
                id="form_do_kubernetes_new_region"
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
                <div>
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
                            wire:click="$set('form.do_kubernetes_new_region', '{{ $regionOption['value'] }}')"
                            x-on:click="open = false"
                            x-show="'{{ Str::lower((string) ($regionOption['label'] ?? '')) }}'.includes(search.toLowerCase()) || '{{ Str::lower((string) ($regionOption['value'] ?? '')) }}'.includes(search.toLowerCase())"
                            aria-selected="{{ $form->do_kubernetes_new_region === $regionOption['value'] ? 'true' : 'false' }}"
                            class="w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 {{ $form->do_kubernetes_new_region === $regionOption['value'] ? 'border-sky-600 bg-sky-50 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' }}"
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
        @endif
    </div>
    <x-input-error :messages="$errors->get('form.do_kubernetes_new_region')" class="mt-1" />
</div>
