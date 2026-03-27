<?php

namespace App\Jobs;

use App\Models\EdgeDeployment;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Deploy\EdgeDeployContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunEdgeDeploymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $edgeDeploymentId,
    ) {}

    public function handle(DeployEngineResolver $deployEngineResolver): void
    {
        $deployment = EdgeDeployment::query()->find($this->edgeDeploymentId);
        if ($deployment === null) {
            return;
        }

        $deployment->update(['status' => EdgeDeployment::STATUS_RUNNING]);

        try {
            $providerConfig = $this->providerConfigForDeployment($deployment);

            $result = $deployEngineResolver->default()->run(new EdgeDeployContext(
                applicationName: $deployment->application_name,
                framework: $deployment->framework,
                gitRef: $deployment->git_ref,
                trigger: $deployment->trigger,
                providerConfig: $providerConfig,
            ));
            $deployment->update([
                'status' => EdgeDeployment::STATUS_SUCCEEDED,
                'provisioner_output' => $result['output'],
                'revision_id' => $result['sha'],
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('RunEdgeDeploymentJob failed', [
                'deployment_id' => $deployment->id,
                'exception' => $e->getMessage(),
            ]);
            $deployment->update([
                'status' => EdgeDeployment::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function providerConfigForDeployment(EdgeDeployment $deployment): array
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
