<?php

namespace App\Jobs;

use App\Models\CloudDeployment;
use App\Services\Deploy\CloudDeployContext;
use App\Services\Deploy\DeployEngineResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunCloudDeploymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $cloudDeploymentId,
    ) {}

    public function handle(DeployEngineResolver $deployEngineResolver): void
    {
        $deployment = CloudDeployment::query()->find($this->cloudDeploymentId);
        if ($deployment === null) {
            return;
        }

        $deployment->update(['status' => CloudDeployment::STATUS_RUNNING]);

        try {
            $providerConfig = $this->providerConfigForDeployment($deployment);

            $result = $deployEngineResolver->default()->run(new CloudDeployContext(
                applicationName: $deployment->application_name,
                stack: $deployment->stack,
                gitRef: $deployment->git_ref,
                trigger: $deployment->trigger,
                providerConfig: $providerConfig,
            ));
            $deployment->update([
                'status' => CloudDeployment::STATUS_SUCCEEDED,
                'provisioner_output' => $result['output'],
                'revision_id' => $result['sha'],
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('RunCloudDeploymentJob failed', [
                'deployment_id' => $deployment->id,
                'exception' => $e->getMessage(),
            ]);
            $deployment->update([
                'status' => CloudDeployment::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function providerConfigForDeployment(CloudDeployment $deployment): array
    {
        $deployment->loadMissing('project');
        $project = $deployment->project;
        if ($project === null) {
            return [];
        }

        $config = [
            'project' => [
                'id' => $project->id,
                'slug' => $project->slug,
                'settings' => is_array($project->settings) ? $project->settings : [],
            ],
        ];

        $credentials = $project->credentials;
        if (is_array($credentials) && $credentials !== []) {
            $config['credentials'] = $credentials;
        }

        return $config;
    }
}
