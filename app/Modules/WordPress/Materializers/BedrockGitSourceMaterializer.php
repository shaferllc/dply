<?php

declare(strict_types=1);

namespace App\Modules\WordPress\Materializers;

use App\Models\SiteGitSource;
use App\Modules\WordPress\Contracts\GitSourceMaterializer;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Str;

/**
 * Bedrock: a theme or plugin is a Composer dependency, not a directory. The
 * same site_git_sources row therefore materializes as two composer.json edits
 * — a `vcs` repository entry pointing at the git URL, and a `require` entry —
 * rather than a clone.
 *
 * Nothing is cloned by hand. Composer owns web/app/themes and web/app/plugins
 * via composer/installers, so cloning into them would produce a tree Composer
 * fights with on the next update.
 *
 * Deliberately scoped: a bare `composer require` resolves the whole dependency
 * graph and can drag WordPress core to a new minor as a side effect of adding a
 * theme. `--update-with-dependencies` limits the update to what the new package
 * actually needs.
 */
class BedrockGitSourceMaterializer implements GitSourceMaterializer
{
    public function __construct(private readonly ExecuteRemoteTaskOnServer $executor) {}

    public function layout(): string
    {
        return 'bedrock';
    }

    public function sync(SiteGitSource $source): void
    {
        $deployPath = $this->deployPath($source);
        $keyPath = $this->writeDeployKey($source);

        // Composer shells out to git for a vcs repository, so the deploy key
        // reaches it the same way the classic materializer passes it to git.
        $sshCommand = 'ssh -i '.$keyPath.' -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new';

        $script = sprintf(
            <<<'BASH'
            set -euo pipefail
            cd %1$s
            export GIT_SSH_COMMAND=%2$s
            export COMPOSER_NO_INTERACTION=1
            composer config repositories.%3$s vcs %4$s
            composer require %5$s:%6$s --no-scripts --update-with-dependencies
            composer show %5$s | awk '/^source/ {print $3}'
            BASH,
            escapeshellarg($deployPath),
            escapeshellarg($sshCommand),
            // Repository keys become composer.json object keys, so keep them to
            // a safe slug rather than passing a raw package name through.
            escapeshellarg($this->repositoryKey($source)),
            escapeshellarg($source->repository_url),
            escapeshellarg($this->packageName($source)),
            escapeshellarg('dev-'.$source->git_branch),
        );

        $out = $this->executor->runInlineBash(
            server: $source->site->server,
            name: 'wp-git-source:bedrock-require-'.$source->kind,
            inlineBash: $script,
            timeoutSeconds: 600,
        );

        $this->shredDeployKey($source, $keyPath);

        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('composer require failed: '.$out->getBuffer());
        }

        $source->markSynced($this->lastLine($out->getBuffer()));
    }

    public function remove(SiteGitSource $source): void
    {
        // Drop the require AND the repository entry: an orphan vcs repository
        // means every later composer command on this site still tries to reach
        // a repo it no longer uses.
        $script = sprintf(
            <<<'BASH'
            set -euo pipefail
            cd %1$s
            export COMPOSER_NO_INTERACTION=1
            composer remove %2$s --no-scripts
            composer config --unset repositories.%3$s
            BASH,
            escapeshellarg($this->deployPath($source)),
            escapeshellarg($this->packageName($source)),
            escapeshellarg($this->repositoryKey($source)),
        );

        $out = $this->executor->runInlineBash(
            server: $source->site->server,
            name: 'wp-git-source:bedrock-remove-'.$source->kind,
            inlineBash: $script,
            timeoutSeconds: 300,
        );

        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('composer remove failed: '.$out->getBuffer());
        }
    }

    /**
     * Composer package name. An explicit `composer_package` wins; otherwise it
     * is derived from the repo URL, which is what the package's own
     * composer.json almost always declares (vendor/name from the git path).
     */
    private function packageName(SiteGitSource $source): string
    {
        $explicit = trim((string) $source->composer_package);
        if ($explicit !== '') {
            return $explicit;
        }

        // git@github.com:acme/my-theme.git and https://github.com/acme/my-theme
        // both reduce to acme/my-theme.
        $path = (string) preg_replace('#^.*[:/]([^/:]+/[^/]+?)(?:\.git)?$#', '$1', trim($source->repository_url));

        return $path !== '' && str_contains($path, '/')
            ? strtolower($path)
            : 'dply/'.$source->slug;
    }

    private function repositoryKey(SiteGitSource $source): string
    {
        return 'dply-'.$source->kind.'-'.Str::slug($source->slug);
    }

    private function deployPath(SiteGitSource $source): string
    {
        return '/home/dply/'.$source->site->slug.'/current';
    }

    private function writeDeployKey(SiteGitSource $source): string
    {
        $source->ensureDeployKey();
        $path = '/home/dply/.ssh/dply-git-source-'.$source->id;

        $out = $this->executor->runInlineBash(
            server: $source->site->server,
            name: 'wp-git-source:write-key',
            inlineBash: sprintf(
                'install -d -m 0700 /home/dply/.ssh && printf %s > %s && chmod 0600 %s',
                escapeshellarg(rtrim((string) $source->deploy_key_private, "\n")."\n"),
                escapeshellarg($path),
                escapeshellarg($path),
            ),
            timeoutSeconds: 60,
        );

        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('could not stage the deploy key: '.$out->getBuffer());
        }

        return $path;
    }

    private function shredDeployKey(SiteGitSource $source, string $path): void
    {
        // Best-effort: a cleanup failure must not mask the sync result the
        // caller is about to report.
        $this->executor->runInlineBash(
            server: $source->site->server,
            name: 'wp-git-source:shred-key',
            inlineBash: 'rm -f '.escapeshellarg($path),
            timeoutSeconds: 30,
        );
    }

    private function lastLine(string $buffer): ?string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $buffer)), fn ($l) => $l !== ''));

        return $lines === [] ? null : end($lines);
    }
}
