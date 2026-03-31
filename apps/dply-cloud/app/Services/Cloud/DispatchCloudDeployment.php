<?php

namespace App\Services\Cloud;

use App\Jobs\RunCloudDeploymentJob;
use App\Models\CloudDeployment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DispatchCloudDeployment
{
    public function __construct(
        private CloudDeployRequestParser $parser,
    ) {}

    public function fromRequest(Request $request, string $trigger): CloudDeployment
    {
        $params = $this->parser->parse($request);

        return DB::transaction(function () use ($params, $trigger): CloudDeployment {
            $key = $params['idempotency_key'];
            if ($key !== null) {
                $existing = $this->findActiveIdempotentDeployment(
                    $key,
                    $params['cloud_project_id'],
                    $trigger,
                );
                if ($existing !== null) {
                    return $existing;
                }
            }

            $deployment = CloudDeployment::query()->create([
                'cloud_project_id' => $params['cloud_project_id'],
                'application_name' => $params['application_name'],
                'stack' => $params['stack'],
                'git_ref' => $params['git_ref'],
                'status' => CloudDeployment::STATUS_QUEUED,
                'trigger' => $trigger,
                'idempotency_key' => $key,
            ]);

            DB::afterCommit(function () use ($deployment): void {
                RunCloudDeploymentJob::dispatch($deployment->id);
            });

            return $deployment;
        });
    }

    /**
     * Reuses queued, in-flight, or successful deploys for the same key (per project + trigger).
     * Failed deploys do not block a new attempt with the same key.
     */
    private function findActiveIdempotentDeployment(string $key, int $cloudProjectId, string $trigger): ?CloudDeployment
    {
        /** @var CloudDeployment|null */
        return CloudDeployment::query()
            ->where('idempotency_key', $key)
            ->where('cloud_project_id', $cloudProjectId)
            ->where('trigger', $trigger)
            ->whereIn('status', [
                CloudDeployment::STATUS_QUEUED,
                CloudDeployment::STATUS_RUNNING,
                CloudDeployment::STATUS_SUCCEEDED,
            ])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }
}
