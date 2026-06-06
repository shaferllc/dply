<?php

declare(strict_types=1);

namespace App\Services\SourceControl;

use App\Contracts\SourceControl\GitIdentity;
use App\Models\GitProviderToken;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;

/**
 * Central lookup for {@see GitIdentity} instances. Wizards persist a bare
 * ULID in Site.git_source_control_account_id (and similar columns) without
 * remembering whether it points at an OAuth account or a PAT — this resolver
 * checks both tables so callers don't have to.
 *
 * OAuth wins ties because that's the storage path that existed first; if a
 * user has both kinds for the same provider, OAuth is the default identity
 * for "any token will do" lookups in {@see SourceControlRepositoryReader}
 * and {@see SiteGitCommitsFetcher}.
 */
class GitIdentityResolver
{
    /**
     * Per-instance memo of resolved identities, keyed by "user:id". A single
     * site render fans out to forSite() for multiple providers (commits
     * fetcher + repo reader), each re-resolving the same stored account ID;
     * caching here collapses the duplicate social_accounts/git_provider_tokens
     * lookups. Bound as a singleton (see AppServiceProvider) so the cache is
     * shared across the whole request.
     *
     * @var array<string, GitIdentity|null>
     */
    private array $byId = [];

    /**
     * Resolve a stored identity ID to either a SocialAccount or a
     * GitProviderToken, scoped to the given user. Returns null when the ID
     * is unknown or belongs to a different user.
     */
    public function forId(User $user, ?string $id): ?GitIdentity
    {
        $id = trim((string) $id);
        if ($id === '') {
            return null;
        }

        $cacheKey = $user->getKey().':'.$id;
        if (array_key_exists($cacheKey, $this->byId)) {
            return $this->byId[$cacheKey];
        }

        $oauth = SocialAccount::query()
            ->where('user_id', $user->getKey())
            ->find($id);
        if ($oauth instanceof GitIdentity) {
            return $this->byId[$cacheKey] = $oauth;
        }

        $pat = GitProviderToken::query()
            ->where('user_id', $user->getKey())
            ->find($id);
        if ($pat instanceof GitIdentity) {
            return $this->byId[$cacheKey] = $pat;
        }

        return $this->byId[$cacheKey] = null;
    }

    /**
     * Identity to use for a given Site's read traffic. Prefers the specific
     * account the operator picked when wiring the repo
     * (`meta.repository.git_source_control_account_id`); falls back to
     * "best available" for the provider when nothing was recorded. This is
     * what keeps reads (branch/tag/commit listing) from drifting onto a
     * different identity than the one used to enumerate repos in the first
     * place — important when the user has multiple PATs/OAuth identities
     * for the same provider and only one of them is valid for this repo.
     */
    public function forSite(Site $site, User $user, string $provider): ?GitIdentity
    {
        $accountId = (string) ($site->repositoryMeta()['git_source_control_account_id'] ?? '');
        if ($accountId !== '') {
            $identity = $this->forId($user, $accountId);
            if ($identity instanceof GitIdentity && $identity->accessToken() !== '' && $identity->provider() === $provider) {
                return $identity;
            }
        }

        return $this->forUserProvider($user, $provider);
    }

    /**
     * "Best available" identity for a user + provider. Used by read-only
     * code paths (commits fetcher, repo reader) that don't care which
     * specific account the operator picked — they just need a usable token.
     */
    public function forUserProvider(User $user, string $provider): ?GitIdentity
    {
        $oauth = SocialAccount::query()
            ->where('user_id', $user->getKey())
            ->where('provider', $provider)
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->orderBy('id')
            ->first();
        if ($oauth instanceof GitIdentity) {
            return $oauth;
        }

        $pat = GitProviderToken::query()
            ->where('user_id', $user->getKey())
            ->where('provider', $provider)
            ->orderBy('id')
            ->first();
        if ($pat instanceof GitIdentity && $pat->accessToken() !== '') {
            return $pat;
        }

        return null;
    }

    /**
     * All identities for the given user, ordered by provider then created_at.
     * Used by the wizards to build the "pick an account" dropdown.
     *
     * @return list<GitIdentity>
     */
    public function allForUser(User $user): array
    {
        $oauth = SocialAccount::query()
            ->where('user_id', $user->getKey())
            ->whereIn('provider', ['github', 'gitlab', 'bitbucket'])
            ->orderBy('provider')
            ->orderBy('id')
            ->get()
            ->all();

        $pats = GitProviderToken::query()
            ->where('user_id', $user->getKey())
            ->whereIn('provider', ['github', 'gitlab', 'bitbucket'])
            ->orderBy('provider')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_merge($oauth, $pats));
    }
}
