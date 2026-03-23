<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Sites\SiteGitDeployer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunSiteDeploymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(
        public Site $site,
        public string $trigger = SiteDeployment::TRIGGER_MANUAL
    ) {}

    public function handle(SiteGitDeployer $deployer): void
    {
        $this->site = $this->site->fresh();
        if (! $this->site) {
            return;
        }

        $deployment = SiteDeployment::query()->create([
            'site_id' => $this->site->id,
            'trigger' => $this->trigger,
            'status' => SiteDeployment::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $result = $deployer->run($this->site);
            $deployment->update([
                'status' => SiteDeployment::STATUS_SUCCESS,
                'git_sha' => $result['sha'],
                'exit_code' => 0,
                'log_output' => $result['output'],
                'finished_at' => now(),
            ]);
            $this->site->update(['last_deploy_at' => now()]);
        } catch (\Throwable $e) {
            $deployment->update([
                'status' => SiteDeployment::STATUS_FAILED,
                'exit_code' => 1,
                'log_output' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            Log::warning('RunSiteDeploymentJob failed', ['site_id' => $this->site->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
