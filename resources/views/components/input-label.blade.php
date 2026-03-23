@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-brand-ink']) }}>
    {{ $value ?? $slot }}
</label>
