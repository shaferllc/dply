@props([
    'label',
    'value',
    'barColor',
    'barWidth',
])

<div>
    <div class="flex items-baseline justify-between gap-2">
        <span class="text-xxs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $label }}</span>
        <span class="font-mono text-sm font-semibold tabular-nums leading-none text-brand-ink">{{ $value }}</span>
    </div>
    <div class="mt-1 h-1 w-full overflow-hidden rounded-full bg-brand-ink/5">
        <div class="h-full rounded-full {{ $barColor }} transition-[width] duration-500" style="width: {{ $barWidth }}%"></div>
    </div>
</div>
