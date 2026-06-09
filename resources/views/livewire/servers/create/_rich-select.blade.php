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
        @if ($optionsCollection->isEmpty())
            <div class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-5 text-sm text-slate-500">
                {{ __('No options available right now.') }}
            </div>
        @else
            <button
                id="{{ $id }}"
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
                    <div class="max-h-72 space-y-2 overflow-y-auto overscroll-contain pr-1">
                        @foreach ($optionsCollection as $opt)
                            @php $isSelected = ($opt['id'] ?? null) === $value; @endphp
                            <button
                                type="button"
                                role="option"
                                wire:click="$set('{{ $field }}', '{{ $opt['id'] }}')"
                                x-on:click="close()"
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
            </template>
        @endif
    </div>
    <x-input-error :messages="$errors->get($errorKey ?? $field)" class="mt-1" />
</div>
