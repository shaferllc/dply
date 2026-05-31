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
    public function __construct(
        private readonly PipelineAnchorScriptExpander $expander,
    ) {}

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
        $script = trim((string) ($this->activePipeline($site)?->clone_script ?? ''));
        if ($script !== '') {
            $log = "\n--- clone (custom) ---\n";
            $log .= $this->execScript($ssh, $site, $script, $releaseDir, $gitSshPrefix, $repoUrl, $branch, 600);

            return $log;
        }

        $repoEsc = escapeshellarg($repoUrl);
        $branchEsc = escapeshellarg($branch);
        $releaseEsc = escapeshellarg($releaseDir);

        if ($atomic) {
            $baseEsc = escapeshellarg(rtrim($site->effectiveRepositoryPath(), '/'));
            $log = "\n--- git clone (atomic) ---\n";
            $log .= $ssh->exec(
                $gitSshPrefix.sprintf('git clone --depth 1 --branch %s %s %s 2>&1', $branchEsc, $repoEsc, $releaseEsc),
                600
            );
            $hasGit = trim($ssh->exec(sprintf('test -d %s/.git && echo ok', $releaseEsc), 30));
            if ($hasGit !== 'ok') {
                throw new \RuntimeException('Git clone failed. See deployment log.');
            }

            return $log;
        }

        if ($hasExistingGit) {
            $log = "\n--- git fetch ---\n";
            $log .= $ssh->exec(
                sprintf('cd %s && %s git fetch origin 2>&1', $releaseEsc, $gitSshPrefix),
                300
            );
            $log .= "\n--- git checkout ---\n";
            $log .= $ssh->exec(
                sprintf('cd %s && %s git checkout %s 2>&1', $releaseEsc, $gitSshPrefix, $branchEsc),
                120
            );
            $log .= "\n--- git pull ---\n";

            return $log.$ssh->exec(
                sprintf('cd %s && %s git pull origin %s 2>&1', $releaseEsc, $gitSshPrefix, $branchEsc),
                300
            );
        }

        $log = "\n--- git clone ---\n";

        return $log.$ssh->exec(
            $gitSshPrefix.sprintf('git clone --branch %s %s %s 2>&1', $branchEsc, $repoEsc, $releaseEsc),
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
        $script = trim((string) ($this->activePipeline($site)?->activate_script ?? ''));
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

        return $log.$ssh->exec(sprintf('ln -sfn %s %s/current', $releaseEsc, $baseEsc), 60);
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

    public function assertReleaseHasGit(RemoteShell $ssh, string $releaseDir): void
    {
        $releaseEsc = escapeshellarg($releaseDir);
        if (trim($ssh->exec(sprintf('test -d %s/.git && echo ok', $releaseEsc), 30)) !== 'ok') {
            throw new \RuntimeException('Clone step did not leave a Git checkout in the release directory.');
        }
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
