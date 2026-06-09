@props([
    'id',
    'label',
    'wireTarget' => null,
    'placeholder' => null,
    'autocomplete' => 'new-password',
    'mono' => false,
])

@php
    $inputClasses = $attributes->get('class', 'mt-1 block w-full text-sm');
    $attributes = $attributes->except('class');
@endphp

<div
    x-data="{
        copied: false,
        showPassword: false,
        async copyPassword() {
            const v = document.getElementById(@js($id))?.value || '';
            if (! v) {
                return;
            }
            try {
                await navigator.clipboard.writeText(v);
                this.copied = true;
                setTimeout(() => this.copied = false, 1800);
            } catch (e) {}
        },
    }"
>
    <label class="mb-1 flex items-center justify-between gap-2 text-sm font-medium text-brand-ink" for="{{ $id }}">
        <span>{{ $label }}</span>
        <span class="flex shrink-0 flex-wrap items-center justify-end gap-3 text-xs">
            <button type="button" class="font-medium text-brand-sage hover:underline" @click="copyPassword()">
                <span x-show="!copied">{{ __('Copy') }}</span>
                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
            </button>
            <button type="button" class="font-medium text-brand-sage hover:underline" @click="showPassword = !showPassword">
                <span x-show="!showPassword">{{ __('Show') }}</span>
                <span x-show="showPassword" x-cloak>{{ __('Hide') }}</span>
            </button>
            @isset($actions)
                {{ $actions }}
            @endisset
        </span>
    </label>
    <input
        id="{{ $id }}"
        x-bind:type="showPassword ? 'text' : 'password'"
        autocomplete="{{ $autocomplete }}"
        spellcheck="false"
        @if (filled($placeholder))
            placeholder="{{ $placeholder }}"
        @endif
        @if (filled($wireTarget))
            wire:loading.attr="disabled"
            wire:target="{{ $wireTarget }}"
        @endif
        {{ $attributes->merge([
            'class' => trim('dply-input '.$inputClasses.($mono ? ' font-mono' : '')),
        ]) }}
    />
</div>
