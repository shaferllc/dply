@props([
    'label',
    'value' => null,
    'mono' => true,
    'tone' => 'default',
])

{{-- Compact label → value row for dense workspace panels. Sits inside a <dl>
     grid; the wrapping div is valid HTML5 dt/dd grouping. Use instead of
     <x-stat-card> when the value is a fact to read, not a headline number. --}}
<div {{ $attributes->class(['flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-3 py-2']) }}>
    <dt class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ $label }}</dt>
    <dd @class([
        'min-w-0 break-all text-xs',
        'font-mono' => $mono,
        'font-semibold' => ! $mono,
        'text-brand-moss' => $tone === 'muted',
        'text-brand-ink' => $tone !== 'muted',
    ])>{{ $value !== null ? $value : $slot }}</dd>
</div>
