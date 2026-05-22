<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Server;
use App\Models\Site;
use App\Services\SshConnectionFactory;
use RuntimeException;

/**
 * SSH into the dply target server and run `git clone {repo} {site_root}` for
 * the migrated site. Uses dply's existing SshConnection abstraction. The git
 * deploy key must already be installed for the site (per Q12 the user linked
 * their SCM in dply before the migration; dply's site provisioning sets up
 * the deploy key for the site's system user).
 *
 * Idempotent — checks `git rev-parse --is-inside-work-tree` before cloning.
 */
class CloneRepoHandler extends SshDependentHandler
{
    public function __construct(protected SshConnectionFactory $factory) {}

    public static function key(): string
    {
        return ImportMigrationStep::KEY_CLONE_REPO;
    }

    protected function executeOnReadyServer(
        ImportMigrationStep $step,
        ImportServerMigration $migration,
        Server $target,
    ): void {
        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null || $child->target_site_id === null) {
            throw new RuntimeException('clone_repo requires a target_site_id (run create_target_site first).');
        }
        $site = Site::find($child->target_site_id);
        if ($site === null) {
            throw new RuntimeException('Target dply Site missing for clone_repo.');
        }
        if (empty($site->git_repository_url)) {
            throw new RuntimeException('Site has no git_repository_url; nothing to clone.');
        }

        $siteRoot = $this->siteRootPath($site);
        $repoUrl = (string) $site->git_repository_url;
        $branch = (string) ($site->git_branch ?: 'main');
        $sshUser = $this->systemUserFor($site);

        $shell = $this->factory->forServer($target);

        // Probe for an existing checkout — re-runs are no-ops past the first success.
        $probe = $shell->exec('sudo -u '.escapeshellarg($sshUser).' git -C '.escapeshellarg($siteRoot).' rev-parse --is-inside-work-tree 2>/dev/null || true');
        if (trim($probe) === 'true') {
            $step->result_data = ['already_cloned' => true, 'site_root' => $siteRoot];
            $step->save();

            return;
        }

        $clone = sprintf(
            'sudo -u %s git clone --branch %s --single-branch %s %s 2>&1',
            escapeshellarg($sshUser),
            escapeshellarg($branch),
            escapeshellarg($repoUrl),
            escapeshellarg($siteRoot),
        );
        $output = $shell->exec($clone, timeoutSeconds: 600);

        // Verify success — git clone exits non-zero on failure; phpseclib's exec
        // doesn't propagate exit codes here, so verify via the probe again.
        $verify = $shell->exec('sudo -u '.escapeshellarg($sshUser).' git -C '.escapeshellarg($siteRoot).' rev-parse HEAD 2>&1');
        $head = trim($verify);
        if (! preg_match('/^[0-9a-f]{40}$/', $head)) {
            throw new RuntimeException('git clone failed: '.mb_substr($output, 0, 1000));
        }

        $step->result_data = [
            'site_root' => $siteRoot,
            'head' => $head,
            'branch' => $branch,
        ];
        $step->save();
    }

    protected function siteRootPath(Site $site): string
    {
        // dply's default site layout is /home/{system_user}/{slug} for VM-shaped sites.
        // Newer sites store this on the Site model directly, but fall back to convention.
        $explicit = trim((string) ($site->repository_path ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return '/home/'.$this->systemUserFor($site).'/'.$site->slug;
    }

    protected function systemUserFor(Site $site): string
    {
        // dply provisions a system user per site; reuse the slug as the username
        // following the same convention as Sites/Create.
        return $site->slug ?: 'dply';
    }
}
