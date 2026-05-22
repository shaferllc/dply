@props([
    'title' => null,
    'tone' => 'info',
])

{{--
    Collapsible "What is this?" disclosure. Use it next to a card or page heading to give
    operators context without adding visual weight to the default view. Native <details> so
    it works without JS and is keyboard/SR friendly out of the box.

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

    $toneClasses = match ($tone) {
        'warn' => 'border-amber-200 bg-amber-50 text-amber-950',
        default => 'border-brand-ink/10 bg-brand-sand/15 text-brand-ink',
    };

    $summaryToneClasses = match ($tone) {
        'warn' => 'text-amber-900 hover:text-amber-950',
        default => 'text-brand-ink/70 hover:text-brand-ink',
    };
@endphp

<details
    {{ $attributes->class(['group rounded-xl border text-sm', $toneClasses]) }}
>
    <summary
        @class([
            'flex cursor-pointer select-none items-center gap-2 px-4 py-2 text-xs font-medium tracking-wide',
            $summaryToneClasses,
        ])
    >
        <svg
            class="size-3.5 shrink-0 transition-transform group-open:rotate-90"
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
        >
            <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
        </svg>
        <span class="group-open:hidden">{{ $label }}</span>
        <span class="hidden group-open:inline">{{ __('Hide') }}</span>
    </summary>

    <div class="border-t border-current/10 px-4 py-3 leading-relaxed [&_a]:underline [&_code]:rounded [&_code]:bg-brand-ink/5 [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-[0.85em] [&_p+p]:mt-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1">
        {{ $slot }}
    </div>
</details>
