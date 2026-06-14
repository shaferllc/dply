@props([
    /** Small uppercase label above the title, e.g. "Settings". */
    'eyebrow' => null,
    /** Large page title. */
    'title' => '',
    /** Supporting paragraph under the title. */
    'description' => null,
    /** Heroicon slug for the leading badge, e.g. "cog-6-tooth". Ignored when the `leading` slot is provided. */
    'icon' => null,
    /** Icon badge tone — see x-icon-badge ('auto', 'brand', 'amber', …). */
    'tone' => 'auto',
    /** Icon badge size ('default', 'md', 'lg'). */
    'iconSize' => 'md',
])

@php
    // The stat-tile column is optional. When a caller passes the `stats` slot we
    // split the card into a 7/5 grid (matching the canonical Settings → Profile
    // hero); otherwise the intro block spans the full width.
    $hasStats = isset($stats) && trim((string) $stats) !== '';
    $hasLeading = isset($leading) || filled($icon);
@endphp

@php
    $hasTopAction = isset($topAction) && trim((string) $topAction) !== '';
@endphp

{{-- Shared hero header. Props cover the stable bits (eyebrow/title/description/
     icon); the action pills (default slot), top-right action (`topAction`), and
     right-hand stat tiles (`stats`) stay caller-owned because they're
     page-specific. Use the `leading` slot to supply a fully custom badge
     instead of the `icon` heroicon slug. --}}
@php
    $hasActions = trim((string) $slot) !== '';
@endphp

<section {{ $attributes->class(['dply-card overflow-hidden']) }}>
    <div class="p-4 sm:p-5">
        {{-- Top band: identity on the left, stat tiles (+ optional top action)
             pulled up to the right so the header reads as one dense row instead
             of leaving a gap between left actions and right stats. --}}
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-6">
            <div class="flex min-w-0 items-start gap-2.5">
                @if ($hasLeading)
                    @isset($leading)
                        {{ $leading }}
                    @else
                        <x-icon-badge :tone="$tone" :size="$iconSize" class="!h-9 !w-9 !rounded-xl">
                            <x-dynamic-component :component="'heroicon-o-' . $icon" class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                    @endisset
                @endif

                <div class="min-w-0">
                    @if (filled($eyebrow))
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ $eyebrow }}</p>
                    @endif
                    <h2 @class([
                        'text-base font-semibold tracking-tight text-brand-ink',
                        'mt-0.5' => filled($eyebrow),
                    ])>{{ $title }}</h2>
                    @if (filled($description))
                        <p class="mt-1 max-w-xl text-[13px] leading-snug text-brand-moss">{{ $description }}</p>
                    @endif
                </div>
            </div>

            @if ($hasTopAction || $hasStats)
                <div class="flex shrink-0 flex-col gap-3 lg:items-end">
                    @if ($hasTopAction)
                        <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                            {{ $topAction }}
                        </div>
                    @endif
                    @if ($hasStats)
                        <div class="w-full lg:w-auto">
                            {{ $stats }}
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Action pills on their own full-width row so they always lay out
             side-by-side (horizontal), consistent across pages regardless of
             how wide the stat column is or how tall the description grows. --}}
        @if ($hasActions)
            <div class="mt-4 flex flex-wrap items-center gap-2">
                {{ $slot }}
            </div>
        @endif
    </div>

    {{-- Optional joined body: content rendered inside the same card, beneath the
         header band with a divider (e.g. a "how to use this" explainer) so it
         reads as one card instead of a detached second card below. --}}
    @isset($footer)
        <div class="border-t border-brand-ink/10 px-4 py-4 sm:px-5 sm:py-5">
            {{ $footer }}
        </div>
    @endisset
</section>
