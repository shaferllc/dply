<?php

declare(strict_types=1);

namespace App\Services\SourceControl;

use App\Contracts\SourceControl\GitIdentity;
use App\Models\GitProviderToken;
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

        $oauth = SocialAccount::query()
            ->where('user_id', $user->getKey())
            ->find($id);
        if ($oauth instanceof GitIdentity) {
            return $oauth;
        }

        $pat = GitProviderToken::query()
            ->where('user_id', $user->getKey())
            ->find($id);
        if ($pat instanceof GitIdentity) {
            return $pat;
        }

        return null;
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
