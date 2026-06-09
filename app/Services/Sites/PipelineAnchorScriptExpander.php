<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;

/**
 * Placeholders for pipeline clone / activate shell scripts.
 *
 * Includes deploy hook tokens plus release-scoped paths for the current deploy.
 */
final class PipelineAnchorScriptExpander
{
    public function __construct(
        private readonly DeployHookScriptExpander $hookExpander,
    ) {}

    /**
     * @return array<string, string>
     */
    public function tokenMap(
        Site $site,
        string $releaseDir,
        string $gitSshPrefix,
        string $repoUrl,
        string $branch,
    ): array {
        $baseDir = rtrim($site->effectiveRepositoryPath(), '/');
        $atomic = ($site->deploy_strategy ?? 'simple') === 'atomic';

        return array_merge($this->hookExpander->tokenMap($site), [
            '{RELEASE_DIR}' => $releaseDir,
            '{BASE_DIR}' => $baseDir,
            '{REPO_URL}' => $repoUrl,
            '{GIT_SSH_PREFIX}' => $gitSshPrefix,
            '{CURRENT_LINK}' => $atomic ? $baseDir.'/current' : $releaseDir,
        ]);
    }

    public function expand(
        string $script,
        Site $site,
        string $releaseDir,
        string $gitSshPrefix,
        string $repoUrl,
        string $branch,
    ): string {
        $map = $this->tokenMap($site, $releaseDir, $gitSshPrefix, $repoUrl, $branch);
        $out = $script;
        foreach ($map as $token => $value) {
            $out = str_replace($token, $value, $out);
        }

        return $out;
    }
}
