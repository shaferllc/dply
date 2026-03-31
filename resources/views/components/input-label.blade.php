@props(['value', 'required' => false])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-brand-ink']) }}>
    {{ $value ?? $slot }}@if ($required)<span class="text-red-500 ml-0.5" aria-hidden="true">*</span>@endif
</label>
