{{--
    Shared "this panel hasn't fetched from the server yet" state for the engine
    config panels (Apache globals, OLS vhosts / listeners / extapps, …).

    Every one of these used to render the same line of prose — `Click "Reload
    from server" to fetch current values.` — which pointed at a control somewhere
    else in the head. The action belongs where the operator is looking, so the
    instruction became a button. Loading paints a stub of the form that's about
    to arrive rather than a lone spinner.

    Receives:
      $target      Livewire method that loads the panel (also the wire:target).
      $title       Empty-state heading.
      $description Why the panel is empty + what fetching gets you.
      $icon        Heroicon for the empty state.
      $fields      Skeleton field count while loading (default 6).
      $loadingText Status line while the read runs.
--}}
@php
    $fields = $fields ?? 6;
    $icon = $icon ?? 'heroicon-o-cog-6-tooth';
    $loadingText = $loadingText ?? __('Reading config from the server…');
    $bar = 'animate-pulse rounded bg-brand-ink/10';
@endphp

<div wire:loading.block wire:target="{{ $target }}" aria-busy="true" aria-live="polite">
    <span class="sr-only">{{ $loadingText }}</span>
    <p class="flex items-center gap-2 text-xs text-brand-moss">
        <x-spinner class="h-3.5 w-3.5 shrink-0" /> {{ $loadingText }}
    </p>
    <div class="mt-3 grid gap-4 sm:grid-cols-2" aria-hidden="true">
        @foreach (range(1, $fields) as $field)
            <div class="space-y-1.5">
                <div class="h-2.5 w-28 {{ $bar }}"></div>
                <div class="h-8 w-full rounded-lg {{ $bar }}"></div>
            </div>
        @endforeach
    </div>
</div>

{{-- Idle state as one row, not a centred hero: these panels are dense, and a
     tall stacked empty state (big icon, centred title, centred prose, centred
     button) took more height than the form it stands in for. Icon + copy left,
     action right. --}}
<div wire:loading.remove wire:target="{{ $target }}">
    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/10 px-3 py-2.5" role="status">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sand/60 text-brand-moss ring-1 ring-brand-ink/10">
            <x-dynamic-component :component="$icon" class="h-4 w-4" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1 basis-64">
            <p class="text-xs font-semibold text-brand-ink">{{ $title }}</p>
            <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $description }}</p>
        </div>
        <button
            type="button"
            wire:click="{{ $target }}"
            wire:loading.attr="disabled"
            wire:target="{{ $target }}"
            class="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60"
        >
            <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ __('Load from server') }}
        </button>
    </div>
</div>
