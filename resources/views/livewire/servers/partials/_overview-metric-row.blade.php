@props([
    'label',
    'value',
    'barColor',
    'barWidth',
])

<div>
    <div class="flex items-baseline justify-between">
        <span class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $label }}</span>
        <span class="font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ $value }}</span>
    </div>
    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/5">
        <div class="h-full rounded-full {{ $barColor }} transition-[width] duration-500" style="width: {{ $barWidth }}%"></div>
    </div>
</div>
