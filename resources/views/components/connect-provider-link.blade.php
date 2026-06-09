{{--
    Opens the shared "Connect a repository provider" modal (layout-mounted).
    Drop next to a repo / account picker so an operator can link a missing
    GitHub / GitLab / Bitbucket account without leaving the page.
--}}
<button
    type="button"
    x-on:click="$dispatch('open-modal', 'connect-provider')"
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:text-brand-ink hover:underline']) }}
>
    {{ trim($slot->toHtml()) !== '' ? $slot : __('Connect a provider') }}
</button>
