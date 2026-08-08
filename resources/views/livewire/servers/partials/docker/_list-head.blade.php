{{--
    Shared head for the Docker list tabs (Containers / Images / Volumes /
    Networks / Compose / Maintenance).

    Every one of these opened with a bare `<h2>` and a full-size Refresh button
    in a cream bar — the loudest thing on a page whose point is the table below.
    This is the workspace's dense head: icon + title + count pill + note, with
    Refresh as a quiet ghost and any tab-specific action passed in.

    Receives:
      $icon      Heroicon for the head.
      $title     Tab title.
      $target    Livewire loader method (drives the Refresh button + spinner).
      $rows      The loaded collection/array, or null — drives the count pill.
      $countLabel Optional pre-built count string (overrides $rows counting).
      $note      Optional reference line, truncated with full text on hover.
      $actions   Optional extra buttons rendered before Refresh.
--}}
@php
    $rows = $rows ?? null;
    $note = $note ?? null;
    $countLabel = $countLabel ?? (is_array($rows) && $rows !== [] ? (string) count($rows) : null);
@endphp

<x-workspace-panel-head
    dense
    :icon="$icon"
    :title="$title"
    :count="$countLabel"
    :note="$note"
    class="border-b border-brand-ink/10"
>
    <x-slot:actions>
        {{ $actions ?? '' }}
        <button
            type="button"
            wire:click="{{ $target }}"
            wire:loading.attr="disabled"
            wire:target="{{ $target }}"
            title="{{ __('Refresh') }}"
            class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md px-1.5 text-[11px] font-semibold text-brand-moss transition hover:bg-white hover:text-brand-ink hover:shadow-sm disabled:opacity-50"
        >
            <span wire:loading.remove wire:target="{{ $target }}" class="inline-flex">
                <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            </span>
            <span wire:loading wire:target="{{ $target }}" class="inline-flex">
                <x-spinner variant="forest" size="sm" />
            </span>
            <span class="hidden sm:inline">{{ __('Refresh') }}</span>
        </button>
    </x-slot:actions>
</x-workspace-panel-head>
