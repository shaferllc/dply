<?php

namespace App\Jobs;

use App\Models\ServerlessFunctionDeployment;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Deploy\ServerlessDeployContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunServerlessFunctionDeploymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $serverlessFunctionDeploymentId,
    ) {}

    public function handle(DeployEngineResolver $deployEngineResolver): void
    {
        $deployment = ServerlessFunctionDeployment::query()->find($this->serverlessFunctionDeploymentId);
        if ($deployment === null) {
            return;
        }

        $deployment->update(['status' => ServerlessFunctionDeployment::STATUS_RUNNING]);

        try {
            $providerConfig = $this->providerConfigForDeployment($deployment);

            $result = $deployEngineResolver->default()->run(new ServerlessDeployContext(
                functionName: $deployment->function_name,
                runtime: $deployment->runtime,
                artifactPath: $deployment->artifact_path,
                trigger: $deployment->trigger,
                providerConfig: $providerConfig,
            ));
            $deployment->update([
                'status' => ServerlessFunctionDeployment::STATUS_SUCCEEDED,
                'provisioner_output' => $result['output'],
                'revision_id' => $result['sha'],
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('RunServerlessFunctionDeploymentJob failed', [
                'deployment_id' => $deployment->id,
                'exception' => $e->getMessage(),
            ]);
            $deployment->update([
                'status' => ServerlessFunctionDeployment::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function providerConfigForDeployment(ServerlessFunctionDeployment $deployment): array
    {
        if ($deployment->serverless_project_id === null) {
            return [];
        }

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
