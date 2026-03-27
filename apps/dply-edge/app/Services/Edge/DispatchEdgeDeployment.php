<?php

namespace App\Services\Edge;

use App\Jobs\RunEdgeDeploymentJob;
use App\Models\EdgeDeployment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DispatchEdgeDeployment
{
    public function __construct(
        private EdgeDeployRequestParser $parser,
    ) {}

    public function fromRequest(Request $request, string $trigger): EdgeDeployment
    {
        $params = $this->parser->parse($request);

        return DB::transaction(function () use ($params, $trigger): EdgeDeployment {
            $key = $params['idempotency_key'];
            if ($key !== null) {
                $existing = $this->findActiveIdempotentDeployment(
                    $key,
                    $params['edge_project_id'],
                    $trigger,
                );
                if ($existing !== null) {
                    return $existing;
                }
            }

            $deployment = EdgeDeployment::query()->create([
                'edge_project_id' => $params['edge_project_id'],
                'application_name' => $params['application_name'],
                'framework' => $params['framework'],
                'git_ref' => $params['git_ref'],
                'status' => EdgeDeployment::STATUS_QUEUED,
                'trigger' => $trigger,
                'idempotency_key' => $key,
            ]);

            DB::afterCommit(function () use ($deployment): void {
                RunEdgeDeploymentJob::dispatch($deployment->id);
            });

            return $deployment;
        });
    }

    private function findActiveIdempotentDeployment(string $key, int $edgeProjectId, string $trigger): ?EdgeDeployment
    {
        /** @var EdgeDeployment|null */
        return EdgeDeployment::query()
            ->where('idempotency_key', $key)
            ->where('edge_project_id', $edgeProjectId)
            ->where('trigger', $trigger)
            ->whereIn('status', [
                EdgeDeployment::STATUS_QUEUED,
                EdgeDeployment::STATUS_RUNNING,
                EdgeDeployment::STATUS_SUCCEEDED,
            ])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }
}
