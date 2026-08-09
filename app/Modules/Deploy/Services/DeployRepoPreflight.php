<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\GitProviderToken;
use App\Models\Site;
use App\Modules\SourceControl\Contracts\GitIdentity;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\GitProviderTokenHealth;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Support\GitCloneUrl;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Fail-fast repository reachability check, run on the WORKER (control plane)
 * before a deploy touches the server. A dead token, revoked deploy key,
 * deleted repo, or renamed branch otherwise costs a full engine run on the box
 * before surfacing — `git ls-remote` answers in ~2s with git's own error text,
 * which the remediations catalog then recognizes (git_auth_failed & co).
 *
 * Deliberately conservative: it only BLOCKS a deploy on definitive rejections
 * (auth failed, repo/branch not found). Network-shaped failures and local
 * environment problems (no git binary, timeout) skip the check entirely — the
 * worker's connectivity is not the server's, and a preflight must never fail a
 * deploy that would have succeeded.
 */
class DeployRepoPreflight
{
    private const TIMEOUT_SECONDS = 25;

    /**
     * Errors that say more about the WORKER's network than the repository.
     * These skip the preflight instead of failing the deploy.
     */
    private const TRANSIENT_PATTERN = '/Could not resolve host|Connection timed out|Failed to connect to|Connection reset by peer|early EOF|RPC failed|The remote end hung up unexpectedly|GnuTLS recv error|SSL_read|Operation timed out/i';

    /**
     * Definitive credential rejections (as opposed to repo-not-found or
     * network trouble) — these make it worth retrying with ANOTHER stored
     * identity for the same provider.
     */
    private const AUTH_PATTERN = '/Authentication failed|Invalid username or token|invalid credentials|could not read Username|HTTP Basic: Access denied|Support for password authentication was removed/i';

    public function __construct(
        private readonly GitIdentityResolver $identities,
        private readonly SourceControlRepositoryBrowser $browser,
        private readonly GitProviderTokenHealth $tokenHealth,
    ) {}

    /**
     * The blocking error for this site's repository, or null when the repo is
     * reachable (or the check could not run and must not block).
     */
    public function check(Site $site): ?string
    {
        $repo = trim((string) $site->git_repository_url);
        if ($repo === '') {
            return null;
        }

        // Serverless (and a few other surfaces) store GitHub repos as bare
        // "owner/name". git ls-remote would treat that as a local path and
        // fail with "does not appear to be a git repository" — expand first.
        $repo = GitCloneUrl::normalize($repo);

        $keyFile = null;

        try {
            $env = [
                'GIT_TERMINAL_PROMPT' => '0',
                'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/dev/null',
            ];

            $privateKey = (string) ($site->git_deploy_key_private ?? '');
            if ($privateKey !== '') {
                $keyFile = tempnam(sys_get_temp_dir(), 'dply_preflight_key_');
                if ($keyFile === false) {
                    return null;
                }
                file_put_contents($keyFile, $privateKey);
                chmod($keyFile, 0600);
                $env['GIT_SSH_COMMAND'] = 'ssh -i '.escapeshellarg($keyFile)
                    .' -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/dev/null';
            }

            [$refspec, $refLabel] = $this->refspecFor($site);

            // Token-authenticated HTTPS repos get EVERY stored identity for
            // the provider as a candidate (pinned first, healthy tokens ahead
            // of known-bad ones). A credential the host rejects is marked via
            // the token-health prober — so the list page shows "Rejected" and
            // the resolver stops picking it — and the next identity is tried.
            $provider = $this->providerFor($site, $repo, $privateKey !== '');
            $candidates = ($provider !== '' && $site->user !== null)
                ? $this->identities->candidatesForSite($site, $site->user, $provider)
                : [];

            if ($candidates === []) {
                // Deploy key / public / non-token path: single bare attempt.
                return $this->attempt($site, $repo, $repo, $refspec, $refLabel, $env);
            }

            $rejected = 0;
            $lastError = null;
            foreach ($candidates as $identity) {
                $url = $this->browser->authenticatedCloneUrl($identity, $repo);
                $error = $this->attempt($site, $repo, $url, $refspec, $refLabel, $env);
                if ($error === null) {
                    // A fallback identity worked after earlier rejections —
                    // re-pin the site to it so the deployer (which re-resolves
                    // the identity itself) uses the credential that just
                    // passed, not the one the host rejected.
                    if ($rejected > 0) {
                        $this->pinIdentity($site, $identity);
                    }

                    return null;
                }
                if (preg_match(self::AUTH_PATTERN, $error) !== 1) {
                    // Repo/branch problem, not this credential — cycling
                    // identities won't change the answer.
                    return $error;
                }

                $rejected++;
                $this->markRejected($identity);
                $lastError = $error;
            }

            return $lastError."\n\n".trans_choice(
                '{1}The stored Git identity for :provider was rejected and has been marked — replace it under Settings → Source control.|[2,*]All :count stored Git identities for :provider were rejected and have been marked — replace them under Settings → Source control.',
                $rejected,
                ['count' => $rejected, 'provider' => $provider],
            );
        } catch (\Throwable $e) {
            // Environment problem (no git binary, tmp not writable, timeout…)
            // — never block the deploy on the preflight's own failure.
            Log::info('Deploy repo preflight skipped', ['site_id' => $site->id, 'error' => $e->getMessage()]);

            return null;
        } finally {
            if ($keyFile !== null && is_file($keyFile)) {
                @unlink($keyFile);
            }
        }
    }

    /**
     * One `git ls-remote` attempt against a candidate URL. Returns the
     * blocking error string, or null when reachable / transient-skip.
     *
     * @param  array<string, string>  $env
     */
    private function attempt(Site $site, string $repo, string $url, ?string $refspec, ?string $refLabel, array $env): ?string
    {
        // `-c credential.helper=` disables ambient credential helpers
        // (e.g. an operator's keychain on a dev box) so the check tests
        // the credential dply will actually deploy with, nothing else.
        $args = ['git', '-c', 'credential.helper=', 'ls-remote', $url];
        if ($refspec !== null) {
            $args[] = $refspec;
        }

        $process = new Process($args, null, $env, null, self::TIMEOUT_SECONDS);
        $process->run();

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        if (! $process->isSuccessful()) {
            if (preg_match(self::TRANSIENT_PATTERN, $output) === 1) {
                return null;
            }

            return 'Repository preflight failed — the Git host rejected the connection, so the deploy was aborted before touching the server.'
                ."\n\n".$output;
        }

        if ($refspec !== null && trim($process->getOutput()) === '') {
            return sprintf(
                "%s '%s' was not found on the remote repository (%s). It may have been renamed or deleted — update the branch on the site's repository settings, then re-deploy.",
                ucfirst($refLabel),
                $site->git_branch,
                $this->redactedRepo($repo),
            );
        }

        return null;
    }

    /**
     * The token provider for this repo, or '' when the deploy doesn't
     * authenticate with a stored token (deploy key, public, non-HTTP).
     * Mirrors AtomicSiteDeployer: a deploy key wins.
     */
    private function providerFor(Site $site, string $repo, bool $hasDeployKey): string
    {
        if ($hasDeployKey || $site->user === null || ! str_starts_with($repo, 'http')) {
            return '';
        }

        $provider = (string) ($site->repositoryMeta()['git_provider_kind'] ?? '');
        if ($provider === '' || $provider === 'custom') {
            $provider = match (true) {
                str_contains($repo, 'github.com') => 'github',
                str_contains($repo, 'gitlab.com') => 'gitlab',
                str_contains($repo, 'bitbucket.org') => 'bitbucket',
                default => '',
            };
        }

        return $provider;
    }

    /**
     * Stamp a host-rejected identity so the tokens page shows it and the
     * resolver stops preferring it. Both kinds go through the health prober
     * (which asks the provider and records validation_error / expires_at) —
     * PATs and OAuth accounts carry the same health columns.
     */
    private function markRejected(GitIdentity $identity): void
    {
        if ($identity instanceof GitProviderToken || $identity instanceof \App\Models\SocialAccount) {
            try {
                $this->tokenHealth->refresh($identity);
            } catch (\Throwable $e) {
                Log::info('Preflight identity health restamp failed', ['identity_id' => $identity->id(), 'error' => $e->getMessage()]);
            }

            return;
        }

        Log::info('Deploy preflight: Git identity rejected by host', ['identity_id' => $identity->id()]);
    }

    /**
     * Point the site's repository meta at the identity that just passed
     * preflight, so this deploy (and future ones) authenticate with it.
     */
    private function pinIdentity(Site $site, GitIdentity $identity): void
    {
        try {
            $site->mergeRepositoryMeta(['git_source_control_account_id' => (string) $identity->id()]);
            $site->save();

            Log::info('Deploy preflight re-pinned the site to a working Git identity', [
                'site_id' => $site->id,
                'identity_id' => $identity->id(),
            ]);
        } catch (\Throwable $e) {
            // Best-effort — the marked-rejected stamps alone already steer the
            // resolver away from dead tokens for unpinned sites.
            Log::warning('Deploy preflight could not re-pin the Git identity', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The refspec to verify, plus its human label — or [null, null] when the
     * configured ref can't be checked via ls-remote (arbitrary commit SHAs).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function refspecFor(Site $site): array
    {
        $ref = trim((string) ($site->git_branch ?? ''));
        if ($ref === '') {
            return [null, null];
        }

        return match ($site->gitRefKind()) {
            'commit' => [null, null],
            'tag' => ['refs/tags/'.$ref, 'tag'],
            default => ['refs/heads/'.$ref, 'branch'],
        };
    }

    private function redactedRepo(string $repo): string
    {
        return (string) preg_replace('#(https?://)[^@\s]+@#', '$1', $repo);
    }
}
