<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Models\SiteDeployPipeline;

/**
 * Runs optional per-pipeline clone / activate shell scripts, or built-in defaults.
 */
final class PipelineAnchorScriptRunner
{
    /** Max clone attempts when the failure looks like a network blip. */
    private const CLONE_NETWORK_RETRIES = 3;

    /**
     * Failure text that indicates the NETWORK, not the credentials or the
     * repository — the only class of clone failure worth retrying.
     */
    private const TRANSIENT_NETWORK_PATTERN = '/Could not resolve host|Connection timed out|Failed to connect to|Connection reset by peer|early EOF|RPC failed|The remote end hung up unexpectedly|GnuTLS recv error|SSL_read/i';

    public function __construct(
        private readonly PipelineAnchorScriptExpander $expander,
    ) {}

    private static function isTransientNetworkError(string $output): bool
    {
        return preg_match(self::TRANSIENT_NETWORK_PATTERN, $output) === 1;
    }

    /**
     * Whether the checkout already on disk is a clone of the requested repo.
     *
     * Compared credential-stripped and `.git`-insensitive, so an authenticated
     * URL (token injected for private HTTPS repos) still matches the clean URL
     * stored in `origin`. Unknown/unreadable remote counts as a mismatch: it is
     * safer to re-initialise than to build someone else's code.
     */
    private function remoteMatches(RemoteShell $ssh, string $releaseEsc, string $repoUrl): bool
    {
        $origin = trim($ssh->exec(
            sprintf('cd %s && git remote get-url origin 2>/dev/null', $releaseEsc),
            30
        ));

        if ($origin === '') {
            return false;
        }

        return self::normalizeRemote($origin) === self::normalizeRemote($repoUrl);
    }

    private static function stripCredentials(string $url): string
    {
        return (string) preg_replace('#(https?://)[^@\s]+@#', '$1', trim($url));
    }

    private static function normalizeRemote(string $url): string
    {
        $url = strtolower(self::stripCredentials($url));
        $url = preg_replace('#\.git$#', '', rtrim($url, '/')) ?? $url;

        return rtrim((string) $url, '/');
    }

    public function runClone(
        RemoteShell $ssh,
        Site $site,
        string $releaseDir,
        string $gitSshPrefix,
        string $repoUrl,
        string $branch,
        bool $atomic,
        bool $hasExistingGit,
    ): string {
        $script = trim((string) ($this->activePipeline($site)->clone_script ?? ''));
        if ($script !== '') {
            $log = "\n--- clone (custom) ---\n";
            $log .= $this->execScript($ssh, $site, $script, $releaseDir, $gitSshPrefix, $repoUrl, $branch, 600);

            return $log;
        }

        $repoEsc = escapeshellarg($repoUrl);
        $branchEsc = escapeshellarg($branch);
        $releaseEsc = escapeshellarg($releaseDir);
        $refKind = $site->gitRefKind();
        $isCommit = $refKind === 'commit';

        if ($atomic) {
            $log = "\n--- git clone (atomic) ---\n";

            // Bounded retry for NETWORK-shaped failures only (mirrors
            // EdgeRepoCloner): a DNS blip or dropped transfer shouldn't fail
            // the whole deploy. Auth/not-found rejections never match the
            // transient pattern, so they still fail on the first attempt.
            $attempt = 0;
            $hasGit = false;
            do {
                $attempt++;
                if ($attempt > 1) {
                    $log .= sprintf("\n[dply] clone attempt %d/%d — retrying after a network error…\n", $attempt, self::CLONE_NETWORK_RETRIES);
                    // Wipe the partial clone so git gets a clean target dir.
                    $ssh->exec(sprintf('rm -rf %s', $releaseEsc), 120);
                }

                $attemptLog = '';
                if ($isCommit) {
                    // Arbitrary SHAs need full history; --depth 1 --branch <sha>
                    // is not supported by hosts that don't allow reachable-SHA
                    // fetches. Clone the default ref, then checkout the SHA.
                    $attemptLog .= $ssh->exec(
                        $gitSshPrefix.sprintf('git clone %s %s 2>&1', $repoEsc, $releaseEsc),
                        600
                    );
                    $attemptLog .= "\n--- git checkout ---\n";
                    $attemptLog .= $ssh->exec(
                        sprintf('cd %s && %s git checkout %s 2>&1', $releaseEsc, $gitSshPrefix, $branchEsc),
                        120
                    );
                } else {
                    $attemptLog .= $ssh->exec(
                        $gitSshPrefix.sprintf('git clone --depth 1 --branch %s %s %s 2>&1', $branchEsc, $repoEsc, $releaseEsc),
                        600
                    );
                }
                $log .= $attemptLog;
                $hasGit = trim($ssh->exec(sprintf('test -d %s/.git && echo ok', $releaseEsc), 30)) === 'ok';
            } while (! $hasGit && $attempt < self::CLONE_NETWORK_RETRIES && self::isTransientNetworkError($attemptLog));

            if (! $hasGit) {
                // Carry git's own stderr in the exception: this message becomes
                // the deployment's log_output (the accumulated $log is discarded
                // on throw), so without it every surface reads "Git clone
                // failed" with no cause anywhere. Redaction happens at the
                // recording site, so an authenticated URL in the output is safe.
                throw new \RuntimeException(self::withOutputTail('Git clone failed.', $log));
            }

            return $log;
        }

        // An existing checkout is only reusable if it is a checkout of the SAME
        // repository. Changing a site's repository URL used to leave the old
        // clone in place: `git fetch <newUrl>` populated FETCH_HEAD, `git
        // checkout <branch>` landed on the OLD repo's local branch, and the
        // pull failed on unrelated histories — so the deploy kept building the
        // previous repo, and detection kept correctly reporting it. Fall
        // through to the init path below, which re-points origin and
        // hard-resets, overwriting the old tree.
        if ($hasExistingGit && ! $this->remoteMatches($ssh, $releaseEsc, $repoUrl)) {
            $hasExistingGit = false;
            $log = "\n[dply] The checkout on disk points at a different repository — re-initialising it against "
                .self::stripCredentials($repoUrl)."\n";

            // Remove the OLD repository's tracked files before dropping .git.
            // The init path below hard-resets, which overwrites files the new
            // repo tracks but leaves everything else in place — so a Laravel
            // checkout replaced by a Next.js one kept composer.json and artisan
            // sitting there, and runtime detection went on reporting PHP.
            //
            // Deliberately `git ls-files` and not `git clean -fdx`: only files
            // the previous repo tracked are removed. Anything the deploy or the
            // operator put there (.env, node_modules, uploads) is untouched.
            $ssh->exec(sprintf(
                'cd %1$s && git ls-files -z 2>/dev/null | xargs -0 -r rm -f -- 2>/dev/null; '
                .'git ls-files -z 2>/dev/null | xargs -0 -r -n1 dirname 2>/dev/null | sort -ru '
                .'| xargs -r -I{} rmdir -p --ignore-fail-on-non-empty {} 2>/dev/null; true',
                $releaseEsc
            ), 120);

            $ssh->exec(sprintf('rm -rf %s/.git', $releaseEsc), 60);
        }

        if ($hasExistingGit) {
            // Pass the repo URL explicitly to fetch/pull so that an
            // authenticated URL (with an injected token) is actually used,
            // rather than whatever `origin` has stored from a prior run.
            $log = "\n--- git fetch ---\n";
            $log .= $ssh->exec(
                sprintf('cd %s && %s git fetch %s 2>&1', $releaseEsc, $gitSshPrefix, $repoEsc),
                300
            );
            $log .= "\n--- git checkout ---\n";
            $log .= $ssh->exec(
                sprintf('cd %s && %s git checkout %s 2>&1', $releaseEsc, $gitSshPrefix, $branchEsc),
                120
            );
            // SHAs have no upstream to pull; `git pull` would fail with a
            // detached HEAD. Only fast-forward branches.
            if ($isCommit || $refKind === 'tag') {
                return $log;
            }
            $log .= "\n--- git pull ---\n";

            return $log.$ssh->exec(
                sprintf('cd %s && %s git pull %s %s 2>&1', $releaseEsc, $gitSshPrefix, $repoEsc, $branchEsc),
                300
            );
        }

        // Initialise the repository in place rather than `git clone`. Clone
        // refuses to write into a non-empty directory ("destination path …
        // already exists and is not an empty directory") — which happens when
        // the target holds a provisioning placeholder or leftovers from a
        // previous failed deploy. init + fetch + hard-reset is idempotent and
        // overwrites tracked files without choking on a pre-existing folder.
        $log = ($log ?? '')."\n--- git clone ---\n";

        // Store the credential-stripped URL as the remote so tokens are never
        // persisted in .git/config. The fetch command receives the (possibly
        // authenticated) URL directly, so HTTPS private repos authenticate
        // without a stored credential.
        $cleanUrl = (string) preg_replace('#(https?://)[^@\s]+@#', '$1', $repoUrl);
        $cleanEsc = escapeshellarg($cleanUrl);
        $initRemote = sprintf(
            'cd %1$s && git init -q && { git remote add origin %2$s 2>/dev/null || git remote set-url origin %2$s; } && ',
            $releaseEsc,
            $cleanEsc
        );

        if ($isCommit) {
            // Arbitrary SHAs need full history — fetch everything, then reset.
            return $log.$ssh->exec(
                $gitSshPrefix.$initRemote.sprintf('git fetch %1$s 2>&1 && git reset --hard %2$s 2>&1', $repoEsc, $branchEsc),
                600
            );
        }

        if ($refKind === 'tag') {
            return $log.$ssh->exec(
                $gitSshPrefix.$initRemote.sprintf('git fetch %1$s %2$s 2>&1 && git reset --hard FETCH_HEAD 2>&1', $repoEsc, $branchEsc),
                600
            );
        }

        return $log.$ssh->exec(
            $gitSshPrefix.$initRemote.sprintf('git fetch %1$s %2$s 2>&1 && git reset --hard FETCH_HEAD 2>&1 && git checkout -B %2$s FETCH_HEAD 2>&1', $repoEsc, $branchEsc),
            600
        );
    }

    public function runActivate(
        RemoteShell $ssh,
        Site $site,
        string $releaseDir,
        string $gitSshPrefix,
        string $repoUrl,
        string $branch,
    ): string {
        $script = trim((string) ($this->activePipeline($site)->activate_script ?? ''));
        if ($script !== '') {
            $log = "\n--- activate (custom) ---\n";

            return $log.$this->execScript($ssh, $site, $script, $releaseDir, $gitSshPrefix, $repoUrl, $branch, 120);
        }

        if (($site->deploy_strategy ?? 'simple') !== 'atomic') {
            return '';
        }

        $baseEsc = escapeshellarg(rtrim($site->effectiveRepositoryPath(), '/'));
        $releaseEsc = escapeshellarg($releaseDir);
        $log = "\n--- activate release ---\n";

        // The webserver provisioner writes the "awaiting first deploy"
        // placeholder into the atomic doc root (…/current/public), which
        // `mkdir -p`s `current` as a REAL directory — as ROOT. `ln -sfn` only
        // replaces a symlink at the destination; against a real directory it
        // silently nests the link *inside* it (…/current/<release>), leaving
        // the placeholder in place so release steps `cd …/current` into a tree
        // with no `artisan`. So a non-symlink `current` must be removed first —
        // but its contents (e.g. a root-owned .env from an earlier push) can be
        // root-owned while deploys run as the unprivileged deploy user, so a
        // plain `rm -rf` dies with "Permission denied". Use sudo for that one
        // removal (deploys already rely on passwordless sudo for the .env
        // push), falling back to a plain rm where sudo isn't available. A real
        // symlink is left untouched for `ln -sfn` to overwrite directly.
        return $log.$ssh->exec(sprintf(
            'if [ -L %2$s/current ]; then :; '
            .'elif [ -e %2$s/current ]; then sudo -n rm -rf %2$s/current 2>&1 || rm -rf %2$s/current; fi; '
            .'ln -sfn %1$s %2$s/current',
            $releaseEsc,
            $baseEsc
        ), 60);
    }

    public function defaultCloneScriptHint(Site $site): string
    {
        if (($site->deploy_strategy ?? 'simple') === 'atomic') {
            return '{GIT_SSH_PREFIX}git clone --depth 1 --branch {BRANCH} {REPO_URL} {RELEASE_DIR}';
        }

        return "{GIT_SSH_PREFIX}git clone --branch {BRANCH} {REPO_URL} {RELEASE_DIR}\n"
            ."# or, when .git already exists:\n"
            .'cd {RELEASE_DIR} && {GIT_SSH_PREFIX}git fetch origin && git checkout {BRANCH} && git pull origin {BRANCH}';
    }

    /**
     * @param  string  $cloneOutput  what the clone step printed — included in the
     *                               exception so the failure cause survives the throw
     */
    public function assertReleaseHasGit(RemoteShell $ssh, string $releaseDir, string $cloneOutput = ''): void
    {
        $releaseEsc = escapeshellarg($releaseDir);
        if (trim($ssh->exec(sprintf('test -d %s/.git && echo ok', $releaseEsc), 30)) !== 'ok') {
            throw new \RuntimeException(self::withOutputTail(
                'Clone step did not leave a Git checkout in the release directory.',
                $cloneOutput,
            ));
        }
    }

    /**
     * Append the tail of a captured shell log to an error message, so the
     * cause travels with the exception instead of dying with the local $log.
     */
    private static function withOutputTail(string $message, string $output): string
    {
        $tail = trim($output);
        if ($tail === '') {
            return $message.' No output was captured from the clone step.';
        }
        if (mb_strlen($tail) > 1500) {
            $tail = '…'.mb_substr($tail, -1500);
        }

        return $message."\n\n".$tail;
    }

    public function defaultActivateScriptHint(Site $site): string
    {
        if (($site->deploy_strategy ?? 'simple') === 'atomic') {
            return 'ln -sfn {RELEASE_DIR} {BASE_DIR}/current';
        }

        return '# No default activate step for simple deploys — leave empty or run shell here before post-deploy.';
    }

    private function activePipeline(Site $site): ?SiteDeployPipeline
    {
        $site->loadMissing('activeDeployPipeline');

        return $site->activeDeployPipeline;
    }

    private function execScript(
        RemoteShell $ssh,
        Site $site,
        string $script,
        string $releaseDir,
        string $gitSshPrefix,
        string $repoUrl,
        string $branch,
        int $timeout,
    ): string {
        $body = $this->expander->expand($script, $site, $releaseDir, $gitSshPrefix, $repoUrl, $branch);
        $b64 = base64_encode($body);

        return $ssh->exec(
            sprintf(
                'cd %s && echo %s | base64 -d | /usr/bin/env bash 2>&1; printf "\nDPLY_HOOK_EXIT:%%s" "$?"',
                escapeshellarg($releaseDir),
                escapeshellarg($b64)
            ),
            $timeout
        );
    }
}
