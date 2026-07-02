<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\Site;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
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

    public function __construct(
        private readonly GitIdentityResolver $identities,
        private readonly SourceControlRepositoryBrowser $browser,
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

            $url = $this->resolveUrl($site, $repo, $privateKey !== '');

            [$refspec, $refLabel] = $this->refspecFor($site);

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

    /** The clone URL with the same credential the deployers will use. */
    private function resolveUrl(Site $site, string $repo, bool $hasDeployKey): string
    {
        // Mirrors AtomicSiteDeployer: a deploy key wins; otherwise inject the
        // stored token for HTTPS URLs so a private repo authenticates.
        if ($hasDeployKey || $site->user === null || ! str_starts_with($repo, 'http')) {
            return $repo;
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
        if ($provider === '') {
            return $repo;
        }

        $identity = $this->identities->forSite($site, $site->user, $provider);
        if ($identity === null) {
            return $repo;
        }

        return $this->browser->authenticatedCloneUrl($identity, $repo);
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
