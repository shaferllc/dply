@props(['label', 'role' => null])

@if ($label)
    <span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide ring-1 ring-inset '.match ($role) {
        'vhost' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'main' => 'bg-brand-sand/60 text-brand-ink ring-brand-ink/10',
        'snippet', 'fragment', 'dynamic' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'cache', 'metrics', 'module', 'ports' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'pool', 'program', 'ini' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        default => 'bg-brand-sand/40 text-brand-moss ring-brand-ink/10',
    }]) }}>
        {{ $label }}
    </span>
@endif
