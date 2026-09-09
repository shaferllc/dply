<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Console;

use App\Models\Site;
use App\Modules\Deploy\Services\SiteQuickDeployCommitPoller;
use Illuminate\Console\Command;

/**
 * Control-plane tick for Quick deploy poll mode: for each site with
 * meta.repository.quick_deploy_enabled + mode=poll, resolve the deploy-branch
 * tip via the Git provider API and queue a deploy when it differs from the
 * last successful deployment SHA. Never SSHs.
 */
class PollQuickDeployCommitsCommand extends Command
{
    protected $signature = 'dply:poll-quick-deploy-commits';

    protected $description = 'Poll Git remotes for new commits on sites with Quick deploy poll mode enabled.';

    public function handle(SiteQuickDeployCommitPoller $poller): int
    {
        $sites = Site::query()
            ->where('meta->repository->quick_deploy_enabled', true)
            ->where('meta->repository->quick_deploy_mode', 'poll')
            ->whereNotNull('git_repository_url')
            ->where('git_repository_url', '!=', '')
            ->with(['server', 'user'])
            ->get();

        $checked = 0;
        $dispatched = 0;

        foreach ($sites as $site) {
            $result = $poller->poll($site);
            if ($result['checked']) {
                $checked++;
            }
            if ($result['dispatched']) {
                $dispatched++;
            }
        }

        if ($checked > 0 || $dispatched > 0) {
            $this->info("Checked {$checked} site(s); dispatched {$dispatched} deploy(s).");
        }

        return self::SUCCESS;
    }
}
