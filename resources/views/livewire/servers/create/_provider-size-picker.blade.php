{{--
  Slick size picker copied from the original Servers/Create page.
  Expects: $catalog (sizes, size_label), $form.
--}}
@php
    $parsePlanOption = static function (array $opt): array {
        $value = (string) ($opt['value'] ?? '');
        $label = (string) ($opt['label'] ?? '');
        $spec = trim(Str::after($label, '—'));
        $segments = array_values(array_filter(array_map('trim', preg_split('/\s*\/\s*/', $spec) ?: [])));
        $ram = $segments[0] ?? '—';
        $cpu = $segments[1] ?? '—';
        $disk = '—';
        $price = null;
        foreach ($segments as $segment) {
            if (str_contains(strtolower($segment), 'disk')) {
                $disk = $segment;
            }
            if (str_contains($segment, '$')) {
                $price = $segment;
            }
        }
        return [
            'value' => $value,
            'name' => Str::before($label, ' — '),
            'ram' => $ram,
            'cpu' => $cpu,
            'disk' => $disk,
            'price' => $price,
        ];
    };

    $sizeCards = collect($catalog['sizes'] ?? [])->map($parsePlanOption)->values();
    $selectedSizeCard = $sizeCards->firstWhere('value', $form->size);
    $recommendedSizeCard = $sizeCards->first();
@endphp

<div>
    <x-input-label for="form_size" :value="$catalog['size_label'] ?? __('Plan / size')" />
    <div x-data="{ open: false }" class="relative mt-1">
        @if ($sizeCards->isEmpty())
            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-5 text-sm text-slate-500">
                {{ __('Select a region first to load available plans.') }}
            </div>
        @else
            <button
                id="form_size"
                type="button"
                x-on:click="open = !open"
                x-on:keydown.escape.window="open = false"
                x-bind:aria-expanded="open.toString()"
                aria-haspopup="listbox"
                class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-left shadow-sm transition focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                            <span>{{ __('Selected plan') }}</span>
                            @if ($selectedSizeCard && $recommendedSizeCard && $selectedSizeCard['value'] === $recommendedSizeCard['value'])
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] tracking-[0.16em] text-emerald-700 ring-1 ring-emerald-200">{{ __('Recommended') }}</span>
                            @endif
                        </div>
                        @if ($selectedSizeCard)
                            <div class="mt-1 truncate text-sm font-semibold text-slate-900">{{ $selectedSizeCard['name'] }}</div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-600">
                                <span>{{ $selectedSizeCard['ram'] }}</span><span>·</span>
                                <span>{{ $selectedSizeCard['cpu'] }}</span><span>·</span>
                                <span>{{ $selectedSizeCard['disk'] }}</span>
                                @if ($selectedSizeCard['price'])<span>·</span><span>{{ $selectedSizeCard['price'] }}</span>@endif
                            </div>
                        @else
                            <div class="mt-1 text-sm text-slate-500">{{ __('Select a plan') }}</div>
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
                <div class="max-h-96 space-y-2 overflow-y-auto overscroll-contain pr-1">
                    @foreach ($sizeCards as $sizeCard)
                        @php
                            $rawSize = collect($catalog['sizes'] ?? [])->firstWhere('value', $sizeCard['value']);
                        @endphp
                        <button
                            type="button"
                            role="option"
                            wire:click="$set('form.size', '{{ $sizeCard['value'] }}')"
                            x-on:click="open = false"
                            aria-selected="{{ $form->size === $sizeCard['value'] ? 'true' : 'false' }}"
                            class="w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-sky-500/30 {{ $form->size === $sizeCard['value'] ? 'border-sky-600 bg-sky-50 ring-1 ring-sky-600/20' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' }}"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="truncate text-sm font-semibold text-slate-900">{{ $sizeCard['name'] }}</div>
                                        @if (($rawSize['recommendation']['state'] ?? null) === 'good_starting_point')
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-emerald-700 ring-1 ring-emerald-200">{{ __('Good starting point') }}</span>
                                        @elseif (($rawSize['recommendation']['state'] ?? null) === 'too_small')
                                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-amber-700 ring-1 ring-amber-200">{{ __('Too small') }}</span>
                                        @elseif (($rawSize['recommendation']['state'] ?? null) === 'overkill')
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-700 ring-1 ring-slate-200">{{ __('Overkill') }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600">
                                        <span><span class="font-medium text-slate-700">{{ __('RAM') }}:</span> {{ $sizeCard['ram'] }}</span>
                                        <span><span class="font-medium text-slate-700">{{ __('CPU') }}:</span> {{ $sizeCard['cpu'] }}</span>
                                        <span><span class="font-medium text-slate-700">{{ __('Disk') }}:</span> {{ $sizeCard['disk'] }}</span>
                                    </div>
                                    @if ($sizeCard['value'] !== $sizeCard['name'])
                                        <div class="mt-1 truncate text-[11px] text-slate-400">{{ $sizeCard['value'] }}</div>
                                    @endif
                                </div>
                                <div class="shrink-0 text-right">
                                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('Price') }}</div>
                                    <div class="mt-1 text-sm font-semibold text-slate-900">{{ $sizeCard['price'] ?? __('Custom') }}</div>
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
    <x-input-error :messages="$errors->get('form.size')" class="mt-1" />
</div>
