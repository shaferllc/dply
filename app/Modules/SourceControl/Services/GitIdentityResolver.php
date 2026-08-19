<?php

declare(strict_types=1);

namespace App\Modules\SourceControl\Services;

use App\Models\GitProviderToken;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use App\Modules\SourceControl\Contracts\GitIdentity;

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
            if ($identity instanceof GitIdentity && $identity->accessToken() !== '' && $identity->provider() === $provider
                && ! $this->isKnownBad($identity)) {
                return $identity;
            }
            // The pinned token was rejected by the provider or has expired
            // (stamped by the health check / deploy preflight) — fall through
            // to the best available healthy identity instead of failing the
            // deploy with a credential we already know is dead.
        }

        return $this->forUserProvider($user, $provider);
    }

    /**
     * A token the provider has definitively rejected, or whose captured expiry
     * has passed. OAuth accounts have no health stamps and are never "bad".
     */
    public function isKnownBad(GitIdentity $identity): bool
    {
        return $identity instanceof GitProviderToken
            && (filled($identity->validation_error) || ($identity->expires_at?->isPast() ?? false));
    }

    /**
     * Every usable identity for a site + provider, in the order deploy auth
     * should try them: the operator-pinned identity first, then OAuth
     * accounts, then PATs — healthy tokens (most recently validated first)
     * ahead of known-rejected/expired ones, which stay as a last resort.
     *
     * @return list<GitIdentity>
     */
    public function candidatesForSite(Site $site, User $user, string $provider): array
    {
        $candidates = [];
        $seen = [];
        $push = function (?GitIdentity $identity) use (&$candidates, &$seen, $provider): void {
            if ($identity === null || $identity->provider() !== $provider || $identity->accessToken() === '') {
                return;
            }
            // id(), not getKey(): getKey() is an Eloquent method the GitIdentity
            // contract does not declare. id() is the contract's own accessor for
            // the same value (the model's ULID).
            $key = get_class($identity).':'.$identity->id();
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $candidates[] = $identity;
        };

        $accountId = (string) ($site->repositoryMeta()['git_source_control_account_id'] ?? '');
        if ($accountId !== '') {
            $pinned = $this->forId($user, $accountId);
            if ($pinned instanceof GitIdentity && ! $this->isKnownBad($pinned)) {
                $push($pinned);
            }
        }

        foreach (SocialAccount::query()
            ->where('user_id', $user->getKey())
            ->where('provider', $provider)
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->orderBy('id')
            ->get() as $oauth) {
            $push($oauth);
        }

        foreach ($this->patsHealthyFirst($user, $provider) as $pat) {
            $push($pat);
        }

        return $candidates;
    }

    /**
     * A user's PATs for a provider, healthy first (no rejection stamp, not
     * expired; most recently validated wins), known-bad ones trailing.
     *
     * @return list<GitProviderToken>
     */
    private function patsHealthyFirst(User $user, string $provider): array
    {
        return GitProviderToken::query()
            ->where('user_id', $user->getKey())
            ->where('provider', $provider)
            ->orderBy('id')
            ->get()
            ->sortBy([
                fn (GitProviderToken $a, GitProviderToken $b) => $this->isKnownBad($a) <=> $this->isKnownBad($b),
                fn (GitProviderToken $a, GitProviderToken $b) => ($b->last_validated_at?->getTimestamp() ?? 0) <=> ($a->last_validated_at?->getTimestamp() ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * "Best available" identity for a user + provider. Used by read-only
     * code paths (commits fetcher, repo reader) that don't care which
     * specific account the operator picked — they just need a usable token.
     */
    /**
     * The identity to use for WEBHOOK operations.
     *
     * Reading a repo works fine with a PAT, but creating a push hook needs
     * admin:repo_hook — a scope fine-grained PATs usually lack, which GitHub
     * reports as "Resource not accessible by personal access token". dply's own
     * OAuth flow always requests that scope, so an OAuth identity is preferred
     * here even when the repo was connected with a token. The caller's choice is
     * still honoured when no OAuth account exists.
     */
    public function forWebhooks(User $user, string $provider, ?GitIdentity $preferred = null): ?GitIdentity
    {
        $oauth = SocialAccount::query()
            ->where('user_id', $user->getKey())
            ->where('provider', $provider)
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->orderBy('id')
            ->first();

        if ($oauth instanceof GitIdentity && $oauth->accessToken() !== '') {
            return $oauth;
        }

        return $preferred ?? $this->forUserProvider($user, $provider);
    }

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

        // Healthy PATs first — a token the provider already rejected (or that
        // expired) must not shadow a working one the user added later. A bad
        // token is still returned when it's ALL there is (last resort).
        foreach ($this->patsHealthyFirst($user, $provider) as $pat) {
            if ($pat->accessToken() !== '') {
                return $pat;
            }
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

        return array_merge($oauth, $pats);
    }
}
