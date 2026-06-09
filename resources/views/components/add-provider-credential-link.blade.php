{{--
    Opens the shared "Connect a provider" modal. Drop next to a credential picker
    so an operator can link a missing cloud account without leaving the page. Pair
    with one <livewire:credentials.add-provider-credential-modal /> per page.
--}}
@props([
    'provider' => null,
])

<button
    type="button"
    @if ($provider)
        x-on:click="$dispatch('open-add-provider-credential-modal', { provider: @js($provider) })"
    @else
        x-on:click="$dispatch('open-add-provider-credential-modal')"
    @endif
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:text-brand-ink hover:underline']) }}
>
    {{ trim($slot->toHtml()) !== '' ? $slot : __('Connect a provider') }}
</button>
