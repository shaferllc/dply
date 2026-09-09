{{--
    Shared loading / error / empty states for the Docker list tabs.

    These were three bare one-liners per tab — a centred spinner, a line of loose
    red text, and a sentence. Now: a stub of the table that's arriving, a framed
    error that points at the Overview tab (where Install lives, the usual cause
    of "Docker CLI is not installed"), and a real empty state.

    Receives:
      $loading  Bool — a load is in flight.
      $error    String|null — probe failure message.
      $rows     Array|null — loaded rows.
      $icon     Heroicon for the empty state.
      $errorTitle   Headline for the error panel.
      $emptyTitle   Headline for the empty state.
      $emptyDescription  Body for the empty state.
      $columns  Column widths (in 4px units) used to shape the loading stub.
--}}
@php
    $columns = $columns ?? [22, 18, 16, 14, 16];
    $bar = 'animate-pulse rounded bg-brand-ink/10';
@endphp

@if ($loading && $rows === null)
    <div class="px-4 py-3.5 sm:px-5" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ $emptyTitle }}</span>
        <div class="overflow-hidden rounded-xl border border-brand-ink/10">
            <div class="flex items-center gap-3 bg-brand-sand/30 px-3 py-2">
                @foreach ($columns as $width)
                    <span class="h-2 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                @endforeach
            </div>
            <div class="divide-y divide-brand-ink/10 bg-white">
                @foreach (range(1, 4) as $row)
                    <div class="flex items-center gap-3 px-3 py-2">
                        @foreach ($columns as $width)
                            <span class="h-2.5 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@elseif ($error)
    <div class="px-4 py-3.5 sm:px-5">
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-rose-200 bg-rose-50/70 px-3 py-2.5" role="alert">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-rose-700 ring-1 ring-rose-200">
                <x-heroicon-m-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1 basis-64">
                <p class="text-xs font-semibold text-rose-900">{{ $errorTitle }}</p>
                <p class="mt-0.5 break-words text-xs leading-relaxed text-rose-800">{{ $error }}</p>
            </div>
            <button
                type="button"
                wire:click="setWorkspaceTab('overview')"
                class="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-2.5 text-xs font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50"
            >
                {{ __('Open Overview') }}
                <x-heroicon-m-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            </button>
        </div>
    </div>
@else
    <div class="px-4 py-3.5 sm:px-5">
        <x-empty-state compact :icon="$icon" :title="$emptyTitle" :description="$emptyDescription" />
    </div>
@endif
