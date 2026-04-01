@props([
    'compact' => false,
])

<div {{ $attributes->class(['overflow-x-auto rounded-2xl border border-brand-ink/10']) }}>
    {{ $slot }}
</div>
