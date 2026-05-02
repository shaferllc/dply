{{--
  Slick Alpine-driven select used for stack-detail fields on Step 3.
  Required vars (set via @include('...', [...])):
    - $id          : DOM id
    - $label       : visible label string
    - $field       : Livewire form path, e.g. 'form.webserver'
    - $value       : currently selected option id
    - $options     : list<array{id:string, label:string, summary?:string}>
    - $errorKey    : key for $errors->get(...) — usually equals $field
  Optional:
    - $placeholder : shown when no value is selected
    - $eyebrow     : small uppercase eyebrow inside the trigger ("Selected" by default)
--}}
@php
    $placeholder = $placeholder ?? __('Select an option');
    $eyebrow = $eyebrow ?? __('Selected');
    $optionsCollection = collect($options ?? []);
    $selectedOption = $optionsCollection->firstWhere('id', $value);
@endphp

<div>
    <x-input-label :for="$id" :value="$label" />
    <div x-data="{ open: false }" class="relative mt-1">
        @if ($optionsCollection->isEmpty())
            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-5 text-sm text-slate-500">
                {{ __('No options available right now.') }}
            </div>
        @else
            <button
                id="{{ $id }}"
                type="button"
                x-on:click="open = !open"
                x-on:keydown.escape.window="open = false"
                x-bind:aria-expanded="open.toString()"
                aria-haspopup="listbox"
                class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-left shadow-sm transition focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $eyebrow }}</div>
                        @if ($selectedOption)
                            <div class="mt-1 truncate text-sm font-semibold text-slate-900">{{ $selectedOption['label'] }}</div>
                            @if (! empty($selectedOption['summary']))
                                <div class="mt-1 truncate text-xs text-slate-600">{{ $selectedOption['summary'] }}</div>
                            @endif
                        @else
                            <div class="mt-1 text-sm text-slate-500">{{ $placeholder }}</div>
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
                class="absolute z-20 mt-2 w-full rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-200/80"
            >
                <div class="max-h-72 space-y-2 overflow-y-auto overscroll-contain pr-1">
                    @foreach ($optionsCollection as $opt)
                        @php $isSelected = ($opt['id'] ?? null) === $value; @endphp
                        <button
                            type="button"
                            role="option"
                            wire:click="$set('{{ $field }}', '{{ $opt['id'] }}')"
                            x-on:click="open = false"
                            aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                            class="w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 {{ $isSelected ? 'border-sky-600 bg-sky-50 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' }}"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-semibold text-slate-900">{{ $opt['label'] }}</div>
                                    @if (! empty($opt['summary']))
                                        <div class="mt-1 text-xs text-slate-600">{{ $opt['summary'] }}</div>
                                    @endif
                                </div>
                                @if ($isSelected)
                                    <x-heroicon-m-check-circle class="h-5 w-5 shrink-0 text-sky-600" aria-hidden="true" />
                                @endif
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
    <x-input-error :messages="$errors->get($errorKey ?? $field)" class="mt-1" />
</div>
