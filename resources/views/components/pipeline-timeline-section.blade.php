@props([
    'step' => 1,
    'title',
    'description' => null,
    'tone' => 'neutral',
])

@php
    $tones = [
        'prepare' => [
            'header' => 'bg-stone-100/90 border-stone-200/80',
            'badge' => 'bg-stone-600 text-white ring-stone-600/20',
            'body' => 'bg-stone-50/50 border-stone-200/60',
            'accent' => 'border-stone-300/70',
        ],
        'build' => [
            'header' => 'bg-sky-50/90 border-sky-200/70',
            'badge' => 'bg-sky-600 text-white ring-sky-600/20',
            'body' => 'bg-sky-50/30 border-sky-200/50',
            'accent' => 'border-sky-300/60',
        ],
        'activate' => [
            'header' => 'bg-brand-sage/15 border-brand-sage/35',
            'badge' => 'bg-brand-forest text-brand-cream ring-brand-forest/20',
            'body' => 'bg-brand-cream/40 border-brand-sage/25',
            'accent' => 'border-brand-sage/40',
        ],
        'release' => [
            'header' => 'bg-emerald-50/90 border-emerald-200/70',
            'badge' => 'bg-emerald-600 text-white ring-emerald-600/20',
            'body' => 'bg-emerald-50/25 border-emerald-200/50',
            'accent' => 'border-emerald-300/60',
        ],
        'neutral' => [
            'header' => 'bg-brand-sand/60 border-brand-ink/10',
            'badge' => 'bg-brand-ink text-brand-cream ring-brand-ink/20',
            'body' => 'bg-white/50 border-brand-ink/10',
            'accent' => 'border-brand-ink/15',
        ],
    ];
    $t = $tones[$tone] ?? $tones['neutral'];
@endphp

<section
    {{ $attributes->class(['overflow-hidden rounded-xl border shadow-sm', $t['body']]) }}
    aria-labelledby="pipeline-section-{{ $step }}"
>
    <header class="flex items-start gap-3 border-b px-4 py-3 sm:px-5 {{ $t['header'] }}">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-sm font-bold shadow-sm ring-2 {{ $t['badge'] }}">
            {{ $step }}
        </span>
        <div class="min-w-0 flex-1">
            <h4 id="pipeline-section-{{ $step }}" class="text-sm font-semibold text-brand-ink">{{ $title }}</h4>
            @if (filled($description))
                <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $description }}</p>
            @endif
        </div>
    </header>
    <div class="border-l-2 px-4 py-4 sm:px-5 {{ $t['accent'] }}">
        <div class="flex min-h-[2.75rem] min-w-0 flex-wrap items-center gap-2">
            {{ $slot }}
        </div>
    </div>
</section>
