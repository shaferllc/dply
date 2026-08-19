@props([
    // list<array{id:string,provider:string,label:string,kind:string}> — as
    // returned by SourceControlRepositoryBrowser::accountsForUser().
    'accounts' => [],
    // The currently-selected account id (a Livewire property value).
    'selected' => null,
])

@php
    // The picker lists only what the linked identity can actually see. Two
    // things routinely hide a repo the user *knows* they own: an org that has
    // not approved the OAuth app (nothing from it is returned at all), and a
    // fine-grained PAT whose repository selection is narrower than the account.
    // Neither is discoverable from inside the list, so point at the provider
    // page where the grant is made.
    $accountRow = collect($accounts)->firstWhere('id', $selected)
        ?: collect($accounts)->first();

    $provider = is_array($accountRow) ? (string) ($accountRow['provider'] ?? '') : '';
    $isPat = is_array($accountRow) && (string) ($accountRow['kind'] ?? '') === 'pat';

    $githubClientId = (string) config('services.github.client_id', '');

    $manageUrl = match ($provider) {
        'github' => $githubClientId !== ''
            ? 'https://github.com/settings/connections/applications/'.$githubClientId
            : 'https://github.com/settings/applications',
        'gitlab' => 'https://gitlab.com/-/user_settings/applications',
        'bitbucket' => 'https://bitbucket.org/account/settings/app-authorizations/',
        default => null,
    };

    $manageLabel = match ($provider) {
        'github' => $isPat ? __('Review token access on GitHub') : __('Grant organization access on GitHub'),
        'gitlab' => $isPat ? __('Review token scopes on GitLab') : __('Review authorized apps on GitLab'),
        'bitbucket' => $isPat ? __('Review token access on Bitbucket') : __('Review app authorizations on Bitbucket'),
        default => null,
    };

    $reason = $isPat
        ? __('Only repositories this token was scoped to appear here.')
        : __('Only repositories this account can reach appear here — an organization owner may still need to approve dply.');
@endphp

@if ($manageUrl !== null)
    <p {{ $attributes->merge(['class' => 'mt-1.5 flex items-start gap-1.5 text-xs text-brand-moss']) }}>
        <x-heroicon-o-question-mark-circle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
        <span>
            <span class="font-medium text-brand-ink">{{ __('Missing a repository?') }}</span>
            {{ $reason }}
            <a href="{{ $manageUrl }}" target="_blank" rel="noopener noreferrer"
                class="font-medium text-brand-moss underline decoration-brand-sage/60 underline-offset-2 transition-colors hover:text-brand-ink">{{ $manageLabel }}</a>{{ __(', then reconnect the account. You can also paste the repository URL directly.') }}
        </span>
    </p>
@endif
