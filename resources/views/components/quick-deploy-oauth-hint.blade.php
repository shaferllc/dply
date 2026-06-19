@props(['provider' => 'github'])

{{--
    Quick-deploy fallback hint. A GitHub fine-grained PAT must carry the Webhooks
    repository permission (Read and write) to register a push webhook — there is
    no Dply-side scope to flip. OAuth already requests admin:repo_hook, so it
    grants webhook access automatically. Surface that as the low-friction path
    right where the operator enables Quick deploy. GitHub-only: GitLab/Bitbucket
    use different token scopes, so the OAuth advice doesn't transfer.
--}}
@if ($provider === 'github')
    <p {{ $attributes->merge(['class' => 'text-[11px] leading-relaxed text-brand-mist']) }}>
        {{ __('Using a fine-grained token? OAuth grants webhook access automatically.') }}
        <a
            href="{{ route('oauth.redirect', ['provider' => 'github']) }}"
            x-on:click.prevent="window.location.href = @js(route('oauth.redirect', ['provider' => 'github'])) + '?return_to=' + encodeURIComponent(window.location.pathname + window.location.search)"
            class="font-semibold text-brand-forest underline decoration-brand-forest/30 underline-offset-2 hover:decoration-brand-forest"
        >{{ __('Connect GitHub via OAuth →') }}</a>
    </p>
@endif
