<?php

namespace App\Services\Serverless;

use App\Jobs\RunServerlessFunctionDeploymentJob;
use App\Models\ServerlessFunctionDeployment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DispatchServerlessFunctionDeployment
{
    public function __construct(
        private ServerlessDeployRequestParser $parser,
    ) {}

    public function fromRequest(Request $request, string $trigger): ServerlessFunctionDeployment
    {
        $params = $this->parser->parse($request);

        return DB::transaction(function () use ($params, $trigger): ServerlessFunctionDeployment {
            $key = $params['idempotency_key'];
            if ($key !== null) {
                $existing = $this->findActiveIdempotentDeployment(
                    $key,
                    $params['serverless_project_id'],
                    $trigger,
                );
                if ($existing !== null) {
                    return $existing;
                }
            }

            $deployment = ServerlessFunctionDeployment::query()->create([
                'serverless_project_id' => $params['serverless_project_id'],
                'function_name' => $params['function_name'],
                'runtime' => $params['runtime'],
                'artifact_path' => $params['artifact_path'],
                'status' => ServerlessFunctionDeployment::STATUS_QUEUED,
                'trigger' => $trigger,
                'idempotency_key' => $key,
            ]);

            DB::afterCommit(function () use ($deployment): void {
                RunServerlessFunctionDeploymentJob::dispatch($deployment->id);
            });

            return $deployment;
        });
    }

    /**
     * Replays queued, in-flight, or successful deploys for the same key (per project + trigger).
     * Failed deploys do not block a new attempt with the same key.
     */
    private function findActiveIdempotentDeployment(string $key, ?int $serverlessProjectId, string $trigger): ?ServerlessFunctionDeployment
    {
        $q = ServerlessFunctionDeployment::query()
            ->where('idempotency_key', $key)
            ->where('trigger', $trigger)
            ->whereIn('status', [
                ServerlessFunctionDeployment::STATUS_QUEUED,
                ServerlessFunctionDeployment::STATUS_RUNNING,
                ServerlessFunctionDeployment::STATUS_SUCCEEDED,
            ])
            ->orderByDesc('id')
            ->lockForUpdate();

        if ($serverlessProjectId !== null) {
            $q->where('serverless_project_id', $serverlessProjectId);
        } else {
            $q->whereNull('serverless_project_id');
        }

        /** @var ServerlessFunctionDeployment|null */
        return $q->first();
    }
}
