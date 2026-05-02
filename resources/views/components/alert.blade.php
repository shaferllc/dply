@props([
    'tone' => 'info',
])

@php
    $classes = match ($tone) {
        'success' => 'border-green-200 bg-green-50 text-green-900',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-950',
        'danger', 'error' => 'border-red-200 bg-red-50 text-red-900',
        default => 'border-brand-ink/10 bg-brand-sand/20 text-brand-ink',
    };

    $role = in_array($tone, ['danger', 'error'], true) ? 'alert' : 'status';
@endphp

<div {{ $attributes->class(["rounded-xl border px-4 py-3 text-sm $classes"]) }} role="{{ $role }}">
    {{ $slot }}
</div>
