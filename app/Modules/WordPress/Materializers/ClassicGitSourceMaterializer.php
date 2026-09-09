<?php

declare(strict_types=1);

namespace App\Modules\WordPress\Materializers;

use App\Models\SiteGitSource;
use App\Modules\WordPress\Contracts\GitSourceMaterializer;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Classic WordPress: a theme or plugin IS a directory under wp-content, so the
 * repo is cloned straight to its slug directory and updated with a fetch/reset.
 *
 * Reset rather than pull: the working tree on a live server picks up local
 * churn (a plugin writing into its own directory, a half-applied previous
 * sync), and a merge conflict on a production box is a worse outcome than
 * discarding those changes. The repo is the source of truth for these paths.
 */
class ClassicGitSourceMaterializer implements GitSourceMaterializer
{
    public function __construct(private readonly ExecuteRemoteTaskOnServer $executor) {}

    public function layout(): string
    {
        return 'classic';
    }

    public function sync(SiteGitSource $source): void
    {
        $site = $source->site;
        $target = $this->deployPath($source).'/'.$source->relativePath();
        $keyPath = $this->writeDeployKey($source);

        // GIT_SSH_COMMAND carries the per-source deploy key. StrictHostKeyChecking
        // is accept-new rather than no: it still pins the host after first
        // contact, but does not block on an unknown provider host.
        $git = 'GIT_SSH_COMMAND='.escapeshellarg(
            'ssh -i '.$keyPath.' -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new'
        );

        $script = sprintf(
            <<<'BASH'
            set -euo pipefail
            TARGET=%1$s
            mkdir -p "$(dirname "$TARGET")"
            if [ -d "$TARGET/.git" ]; then
                cd "$TARGET"
                %2$s git remote set-url origin %3$s
                %2$s git fetch --depth 1 origin %4$s
                git reset --hard FETCH_HEAD
                git clean -fd
            else
                rm -rf "$TARGET"
                %2$s git clone --depth 1 --branch %4$s %3$s "$TARGET"
                cd "$TARGET"
            fi
            git rev-parse HEAD
            BASH,
            escapeshellarg($target),
            $git,
            escapeshellarg($source->repository_url),
            escapeshellarg($source->git_branch),
        );

        $out = $this->executor->runInlineBash(
            server: $site->server,
            name: 'wp-git-source:sync-'.$source->kind,
            inlineBash: $script,
            timeoutSeconds: 300,
        );

        $this->shredDeployKey($source, $keyPath);

        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('git sync failed: '.$out->getBuffer());
        }

        $source->markSynced($this->lastLine($out->getBuffer()));
    }

    public function remove(SiteGitSource $source): void
    {
        $target = $this->deployPath($source).'/'.$source->relativePath();

        // Guard against ever handing a recursive delete a path that is not
        // inside the site's own themes/plugins directory — a blank slug would
        // otherwise resolve to the wp-content directory itself.
        if ($source->slug === '' || ! str_contains($target, '/wp-content/')) {
            throw new \RuntimeException('Refusing to remove an unexpected path: '.$target);
        }

        $out = $this->executor->runInlineBash(
            server: $source->site->server,
            name: 'wp-git-source:remove-'.$source->kind,
            inlineBash: 'rm -rf '.escapeshellarg($target),
            timeoutSeconds: 60,
        );

        if ($out->getExitCode() !== 0) {
            throw new \RuntimeException('git source removal failed: '.$out->getBuffer());
        }
    }

    private function deployPath(SiteGitSource $source): string
    {
        return '/home/dply/'.$source->site->slug.'/current';
    }

    /**
     * Drop the private key into a 0600 file for the duration of one git call.
     * It is removed again in sync() — a deploy key left on disk is a standing
     * credential for a repo the server otherwise has no business reading.
     */
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
        // caller is about to report, but it is still worth attempting.
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
