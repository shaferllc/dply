<?php

declare(strict_types=1);

namespace App\Modules\WordPress\Services;

use App\Models\SiteGitSource;
use App\Models\User;
use App\Modules\SourceControl\Contracts\GitIdentity;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;

/**
 * Credentials for a theme/plugin repo picked through a connected account.
 *
 * One place for both materializers, so classic (git over HTTPS) and Bedrock
 * (Composer) cannot disagree about which account a source clones with, or how.
 *
 * Nothing here is written to the server. A token that lands in .git/config or
 * composer.json outlives the account it came from and is readable by anything
 * with shell on the box — so the materializers hand credentials to a single
 * command through the environment, never through a persisted file or argv.
 */
final class GitSourceCredentials
{
    public function __construct(
        private readonly GitIdentityResolver $resolver,
        private readonly SourceControlRepositoryBrowser $browser,
    ) {}

    /**
     * Null for a pasted-URL source, which authenticates with its deploy key.
     *
     * Throws when a source WAS connected but its account can no longer be
     * resolved. Quietly falling back to an anonymous clone would turn "reconnect
     * this repository" into a baffling "repository not found".
     */
    public function identityFor(SiteGitSource $source): ?GitIdentity
    {
        $accountId = trim((string) ($source->source_control_account_id ?? ''));
        if ($accountId === '') {
            return null;
        }

        $user = $source->connected_by_user_id !== null
            ? User::query()->find($source->connected_by_user_id)
            : null;
        if ($user === null) {
            throw new \RuntimeException(__('The person who connected this repository no longer has an account. Reconnect it from an account you can use.'));
        }

        $identity = $this->resolver->forId($user, $accountId);
        if ($identity === null) {
            throw new \RuntimeException(__('The connected source-control account for this repository is no longer available. Reconnect it.'));
        }

        return $identity;
    }

    /** Plain HTTPS form of a repo URL — the only form token auth works over. */
    public static function httpsUrl(string $url): string
    {
        $url = trim($url);

        // git@github.com:acme/theme.git -> https://github.com/acme/theme.git
        if (preg_match('#^[^@/\s]+@([^:/\s]+):(.+)$#', $url, $m) === 1) {
            return 'https://'.$m[1].'/'.ltrim($m[2], '/');
        }

        // ssh://git@github.com/acme/theme.git -> https://github.com/acme/theme.git
        if (preg_match('#^ssh://(?:[^@/]+@)?([^:/]+)(?::\d+)?/(.+)$#', $url, $m) === 1) {
            return 'https://'.$m[1].'/'.$m[2];
        }

        return $url;
    }

    /**
     * Host + basic-auth pair for this source's account.
     *
     * Derived from the authenticated clone URL rather than re-deciding the
     * per-provider username here (x-access-token / oauth2 / x-token-auth), so
     * there is exactly one place that knows it: SourceControlRepositoryBrowser.
     *
     * @return array{host: string, username: string, password: string}|null
     */
    public function basicCredentials(SiteGitSource $source, GitIdentity $identity): ?array
    {
        $url = $this->browser->authenticatedCloneUrl($identity, self::httpsUrl((string) $source->repository_url));
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'], $parts['user'], $parts['pass'])) {
            return null;
        }

        return [
            'host' => $parts['host'],
            'username' => rawurldecode($parts['user']),
            'password' => rawurldecode($parts['pass']),
        ];
    }

    /**
     * Environment that authenticates git over HTTPS for one command.
     *
     * git reads GIT_CONFIG_COUNT / GIT_CONFIG_KEY_n / GIT_CONFIG_VALUE_n exactly as
     * if they were `-c` flags, but they never appear in argv (visible to every
     * user via `ps`) and never touch .git/config. The header is scoped to the
     * repo's host so a redirect cannot carry the token anywhere else.
     *
     * @return array<string, string>|null
     */
    public function gitAuthEnv(SiteGitSource $source, GitIdentity $identity): ?array
    {
        $basic = $this->basicCredentials($source, $identity);
        if ($basic === null) {
            return null;
        }

        return [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.https://'.$basic['host'].'/.extraHeader',
            'GIT_CONFIG_VALUE_0' => 'Authorization: Basic '.base64_encode($basic['username'].':'.$basic['password']),
        ];
    }

    /** COMPOSER_AUTH for one composer invocation — never written to auth.json. */
    public function composerAuth(SiteGitSource $source, GitIdentity $identity): ?string
    {
        $basic = $this->basicCredentials($source, $identity);
        if ($basic === null) {
            return null;
        }

        return json_encode([
            'http-basic' => [
                $basic['host'] => ['username' => $basic['username'], 'password' => $basic['password']],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
