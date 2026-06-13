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
<section {{ $attributes->class(['dply-card overflow-hidden']) }}>
    <div class="p-6 sm:p-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                @if ($hasLeading)
                    @isset($leading)
                        {{ $leading }}
                    @else
                        <x-icon-badge :tone="$tone" :size="$iconSize">
                            <x-dynamic-component :component="'heroicon-o-' . $icon" class="h-6 w-6" aria-hidden="true" />
                        </x-icon-badge>
                    @endisset
                @endif

                <div class="min-w-0">
                    @if (filled($eyebrow))
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ $eyebrow }}</p>
                    @endif
                    <h2 @class([
                        'text-xl font-semibold tracking-tight text-brand-ink',
                        'mt-1' => filled($eyebrow),
                    ])>{{ $title }}</h2>
                    @if (filled($description))
                        <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">{{ $description }}</p>
                    @endif
                </div>
            </div>

            @if ($hasTopAction)
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    {{ $topAction }}
                </div>
            @endif
        </div>

        @php
            $hasActions = trim((string) $slot) !== '';
        @endphp

        @if ($hasActions || $hasStats)
            <div @class([
                'mt-6 grid gap-6',
                'lg:grid-cols-12 lg:items-center lg:gap-8' => $hasStats,
            ])>
                @if ($hasActions)
                    <div @class(['lg:col-span-7' => $hasStats])>
                        <div class="flex flex-wrap items-center gap-2">
                            {{ $slot }}
                        </div>
                    </div>
                @endif

                @if ($hasStats)
                    <div @class(['lg:col-span-5' => $hasActions, 'lg:col-span-12' => ! $hasActions])>
                        {{ $stats }}
                    </div>
                @endif
            </div>
        @endif
    </div>
</section>
