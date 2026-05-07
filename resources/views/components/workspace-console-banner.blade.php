@props([
    /**
     * Drives the banner color + icon. One of:
     *   queued / running    -> sky, spinner
     *   completed           -> emerald, check
     *   failed              -> rose, warning
     *   anything else / ''  -> sky (default)
     */
    'status' => '',

    /** Headline text. Required (empty banner is rendered with no message). */
    'message' => '',

    /** Optional subtitle string under the message. Pass `null` to omit. */
    'subtitle' => null,

    /**
     * Streaming-output / transcript lines shown inside the "View output" disclosure.
     *
     * @var list<string>
     */
    'output' => [],

    /** True while a backing job is queued/running — shows the spinner icon. */
    'busy' => false,

    /** Livewire method to call when Dismiss is clicked. `null` hides the button. */
    'dismissAction' => null,

    /** Livewire method to wire:poll while busy. `null` disables polling. */
    'pollAction' => null,

    /** wire:poll modifier (e.g. '4s', '2s', '1000ms'). */
    'pollInterval' => '4s',

    /** Initial expanded state of the output panel. */
    'defaultExpanded' => false,

    /** Override the message shown inside the output panel when `$output` is empty. */
    'emptyMessage' => null,
])

@php
    $bannerClasses = match ($status) {
        'failed' => 'border-rose-200 bg-rose-50/80 text-rose-900',
        'completed' => 'border-emerald-200 bg-emerald-50/70 text-emerald-900',
        default => 'border-sky-200 bg-sky-50/80 text-sky-900',
    };
    $resolvedEmpty = $emptyMessage ?? ($busy
        ? __('No output yet — the worker may still be picking up the job.')
        : __('No output recorded.'));
@endphp

@if ($busy && $pollAction !== null)
    {{-- Polling element only mounts while a run is in flight; the moment status leaves
         queued/running, this disappears and polling stops. --}}
    <div wire:poll.{{ $pollInterval }}="{{ $pollAction }}" class="hidden" aria-hidden="true"></div>
@endif

<div
    class="mb-2 overflow-hidden rounded-xl border {{ $bannerClasses }} text-sm shadow-sm w-full"
    role="status"
    aria-live="polite"
    x-data="{ expanded: @js($busy || $status === 'failed' || $defaultExpanded) }"
>
    <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:gap-4">
        <div class="flex min-w-0 flex-1 items-center gap-3">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/70 ring-1 ring-current/20">
                @if ($busy)
                    <x-spinner variant="forest" />
                @elseif ($status === 'completed')
                    <x-heroicon-o-check-circle class="h-4 w-4" />
                @elseif ($status === 'failed')
                    <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
                @else
                    <x-heroicon-o-information-circle class="h-4 w-4" />
                @endif
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate font-semibold leading-tight">{{ $message }}</p>
                @if ($subtitle !== null && $subtitle !== '')
                    <p class="mt-0.5 break-all text-xs opacity-80">{{ $subtitle }}</p>
                @endif
            </div>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
            @if (! $busy && $dismissAction !== null)
                <button type="button" wire:click="{{ $dismissAction }}" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-current/20 bg-white px-2.5 py-1.5 text-xs font-medium shadow-sm hover:bg-white/80">
                    <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                    {{ __('Dismiss') }}
                </button>
            @endif
            <button
                type="button"
                x-on:click="expanded = !expanded"
                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-current/20 bg-white px-2.5 py-1.5 text-xs font-medium shadow-sm hover:bg-white/80"
                x-bind:aria-expanded="expanded.toString()"
            >
                <x-heroicon-o-chevron-down class="h-3.5 w-3.5 transition-transform" x-bind:class="expanded ? 'rotate-180' : ''" />
                <span x-text="expanded ? @js(__('Hide output')) : @js(__('View output'))"></span>
            </button>
        </div>
    </div>
    <div x-show="expanded" x-cloak class="border-t border-current/15 bg-white/70 px-4 py-3">
        @if (empty($output))
            <p class="text-xs opacity-80">{{ $resolvedEmpty }}</p>
        @else
            <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100" x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">@foreach ($output as $line){{ $line }}
@endforeach</pre>
        @endif
    </div>
</div>
