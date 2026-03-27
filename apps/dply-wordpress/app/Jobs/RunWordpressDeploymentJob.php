<?php

namespace App\Jobs;

use App\Models\WordpressDeployment;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Deploy\WordpressDeployContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunWordpressDeploymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $wordpressDeploymentId,
    ) {}

    public function handle(DeployEngineResolver $deployEngineResolver): void
    {
        $deployment = WordpressDeployment::query()->find($this->wordpressDeploymentId);
        if ($deployment === null) {
            return;
        }

        $deployment->update(['status' => WordpressDeployment::STATUS_RUNNING]);

        try {
            $providerConfig = $this->providerConfigForDeployment($deployment);

            $result = $deployEngineResolver->default()->run(new WordpressDeployContext(
                applicationName: $deployment->application_name,
                phpVersion: $deployment->php_version,
                gitRef: $deployment->git_ref,
                trigger: $deployment->trigger,
                providerConfig: $providerConfig,
            ));
            $deployment->update([
                'status' => WordpressDeployment::STATUS_SUCCEEDED,
                'provisioner_output' => $result['output'],
                'revision_id' => $result['sha'],
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('RunWordpressDeploymentJob failed', [
                'deployment_id' => $deployment->id,
                'exception' => $e->getMessage(),
            ]);
            $deployment->update([
                'status' => WordpressDeployment::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function providerConfigForDeployment(WordpressDeployment $deployment): array
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
