@props([
    'title' => null,
    'tone' => 'info',
])

{{--
    "What is this?" contextual help. Renders a compact info trigger with a hover/focus
    tooltip; clicking it opens a modal popup with the body content. Use it next to a card
    or page heading to give operators context without adding visual weight to the default
    view. Self-contained Alpine (own `open` state, teleported overlay) so multiple
    explainers on one page never collide.

    Voice guide for callers (write the body slot):
      - Lead with what the operator is looking at, not the implementation.
      - Say where the data comes from when it isn't obvious ("read live via SSH each render",
        "from the last provision", "cached for ~10 min, refresh with Recheck").
      - Mutating actions: state the consequence (drops connections / requires restart /
        persists across reboots) before the verb.
      - Avoid marketing voice. Avoid "easily" / "simply" / "just".
      - Sentence case in every label and tooltip. Period at the end of complete sentences.
--}}

@php
    $label = $title ?? __('What is this?');

    $triggerToneClasses = match ($tone) {
        'warn' => 'border-amber-200 bg-amber-50 text-amber-900 hover:bg-amber-100 hover:text-amber-950',
        default => 'border-brand-ink/10 bg-brand-sand/15 text-brand-ink/70 hover:bg-brand-sand/30 hover:text-brand-ink',
    };
@endphp

<div
    x-data="{ open: false }"
    {{ $attributes->class(['inline-flex']) }}
>
    <x-tooltip :label="$label">
        <button
            type="button"
            x-on:click="open = true"
            @class([
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium tracking-wide transition',
                $triggerToneClasses,
            ])
            aria-haspopup="dialog"
        >
            <svg class="size-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M18 10A8 8 0 1 1 2 10a8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd" />
            </svg>
            <span>{{ $label }}</span>
        </button>
    </x-tooltip>

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-on:keydown.escape.window="open = false"
            class="fixed inset-0 z-[100] flex items-start justify-center overflow-y-auto px-4 py-6 sm:px-0"
            role="dialog"
            aria-modal="true"
        >
            <div
                x-show="open"
                x-on:click="open = false"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-black/50"
            ></div>

            <div
                x-show="open"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                class="dply-modal-panel relative z-10 mb-6 w-full max-w-lg overflow-hidden shadow-xl sm:mx-auto"
            >
                <div class="flex items-start justify-between gap-4 border-b border-brand-ink/10 px-6 py-4">
                    <h2 class="text-base font-semibold text-brand-ink">{{ $label }}</h2>
                    <button
                        type="button"
                        x-on:click="open = false"
                        class="-mr-1 rounded-md p-1 text-brand-ink/40 transition hover:bg-brand-ink/5 hover:text-brand-ink"
                        aria-label="{{ __('Close') }}"
                    >
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                        </svg>
                    </button>
                </div>

                <div class="px-6 py-5 text-sm leading-relaxed text-brand-ink [&_a]:underline [&_code]:rounded [&_code]:bg-brand-ink/5 [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-[0.85em] [&_p+p]:mt-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </template>
</div>
