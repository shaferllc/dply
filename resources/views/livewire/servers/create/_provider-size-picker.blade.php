{{--
  Slick size picker copied from the original Servers/Create page.
  Expects: $catalog (sizes, size_label), $form.
--}}
@php
    $parsePlanOption = static function (array $opt): array {
        $value = (string) ($opt['value'] ?? '');
        $label = (string) ($opt['label'] ?? '');

        $memoryMb = isset($opt['memory_mb']) && is_numeric($opt['memory_mb']) ? (int) $opt['memory_mb'] : 0;
        $vcpus = isset($opt['vcpus']) && is_numeric($opt['vcpus']) ? (int) $opt['vcpus'] : 0;
        $diskGb = isset($opt['disk_gb']) && is_numeric($opt['disk_gb']) ? (int) $opt['disk_gb'] : 0;
        $monthly = isset($opt['price_monthly']) && is_numeric($opt['price_monthly']) ? (float) $opt['price_monthly'] : null;

        if ($memoryMb > 0 || $vcpus > 0 || $diskGb > 0 || $monthly !== null) {
            $ram = $memoryMb >= 1024
                ? sprintf('%dGB', (int) round($memoryMb / 1024))
                : ($memoryMb > 0 ? $memoryMb.'MB' : '—');
            $cpu = $vcpus > 0 ? $vcpus.' vCPU' : '—';
            $disk = $diskGb > 0 ? $diskGb.'GB disk' : '—';
            $price = ($monthly !== null && $monthly > 0)
                ? '$'.number_format($monthly, $monthly < 10 ? 2 : 0).'/mo'
                : null;

            return [
                'value' => $value,
                'name' => Str::before($label, ' — ') ?: $value,
                'ram' => $ram,
                'cpu' => $cpu,
                'disk' => $disk,
                'price' => $price,
            ];
        }

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
    <div
        x-data="{
            open: false,
            position: { top: 0, left: 0, width: 0 },
            compute() {
                const trigger = this.$refs.trigger;
                const menu = this.$refs.menu;
                if (! trigger || ! menu) {
                    return;
                }
                const r = trigger.getBoundingClientRect();
                const gap = 8;
                const margin = 8;
                const mw = menu.offsetWidth || r.width;
                const mh = menu.offsetHeight || 1;
                let top = r.bottom + gap;
                let left = r.left;
                const width = r.width;
                if (top + mh > window.innerHeight - margin) {
                    top = r.top - mh - gap;
                }
                left = Math.max(margin, Math.min(left, window.innerWidth - mw - margin));
                top = Math.max(margin, Math.min(top, window.innerHeight - mh - margin));
                this.position = { top, left, width };
            },
            toggle() {
                this.open = ! this.open;
                if (this.open) {
                    this.$nextTick(() => this.compute());
                }
            },
            close() {
                this.open = false;
            },
        }"
        x-on:click.outside="close()"
        class="relative mt-1"
    >
        @if ($sizeCards->isEmpty())
            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-5 text-sm text-slate-500">
                {{ __('Select a region first to load available plans.') }}
            </div>
        @else
            <button
                id="form_size"
                type="button"
                x-ref="trigger"
                x-on:click.stop="toggle()"
                x-on:keydown.escape.window="close()"
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

            <template x-teleport="body">
                <div
                    x-ref="menu"
                    x-cloak
                    x-show="open"
                    x-transition.origin.top
                    x-on:scroll.window.passive="open && compute()"
                    x-on:resize.window.passive="open && compute()"
                    x-bind:style="`top: ${position.top}px; left: ${position.left}px; width: ${position.width}px;`"
                    role="listbox"
                    class="fixed z-[80] rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-200/80"
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
                                x-on:click="close()"
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
            </template>
        @endif
    </div>
    <x-input-error :messages="$errors->get('form.size')" class="mt-1" />
</div>
