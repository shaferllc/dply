<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Jobs;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Deploy\Services\DigitalOceanFunctionsActionDeployer;
use App\Support\DeployLogRedactor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Rolls a serverless function back by re-deploying a previously built
 * artifact — no rebuild, no checkout. Records a SiteDeployment so the
 * rollback shows up in deploy history and the journey, exactly like a
 * normal deploy.
 */
class RollbackServerlessFunctionJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public string $siteId, public string $artifactPath) {}

    public function handle(DigitalOceanFunctionsActionDeployer $deployer): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        $deployment = SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'trigger' => SiteDeployment::TRIGGER_MANUAL,
            'status' => SiteDeployment::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $result = $deployer->redeployArtifact($site, $this->artifactPath);

            $deployment->update([
                'status' => SiteDeployment::STATUS_SUCCESS,
                'exit_code' => 0,
                'git_sha' => $result['revision_id'],
                'log_output' => DeployLogRedactor::redact($result['output']),
                'finished_at' => now(),
            ]);
            $site->forceFill(['last_deploy_at' => now()])->save();
        } catch (Throwable $e) {
            $deployment->update([
                'status' => SiteDeployment::STATUS_FAILED,
                'exit_code' => 1,
                'log_output' => DeployLogRedactor::redact($e->getMessage()),
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }
}
