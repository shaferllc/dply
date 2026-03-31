<?php

namespace App\Services\Wordpress;

use App\Jobs\RunWordpressDeploymentJob;
use App\Models\WordpressDeployment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DispatchWordpressDeployment
{
    public function __construct(
        private WordpressDeployRequestParser $parser,
    ) {}

    public function fromRequest(Request $request, string $trigger): WordpressDeployment
    {
        $params = $this->parser->parse($request);

        return DB::transaction(function () use ($params, $trigger): WordpressDeployment {
            $key = $params['idempotency_key'];
            if ($key !== null) {
                $existing = $this->findActiveIdempotentDeployment(
                    $key,
                    $params['wordpress_project_id'],
                    $trigger,
                );
                if ($existing !== null) {
                    return $existing;
                }
            }

            $deployment = WordpressDeployment::query()->create([
                'wordpress_project_id' => $params['wordpress_project_id'],
                'application_name' => $params['application_name'],
                'php_version' => $params['php_version'],
                'git_ref' => $params['git_ref'],
                'status' => WordpressDeployment::STATUS_QUEUED,
                'trigger' => $trigger,
                'idempotency_key' => $key,
            ]);

            DB::afterCommit(function () use ($deployment): void {
                RunWordpressDeploymentJob::dispatch($deployment->id);
            });

            return $deployment;
        });
    }

    /**
     * Reuses queued, in-flight, or successful deploys for the same key (per project + trigger).
     * Failed deploys do not block a new attempt with the same key.
     */
    private function findActiveIdempotentDeployment(string $key, int $wordpressProjectId, string $trigger): ?WordpressDeployment
    {
        /** @var WordpressDeployment|null */
        return WordpressDeployment::query()
            ->where('idempotency_key', $key)
            ->where('wordpress_project_id', $wordpressProjectId)
            ->where('trigger', $trigger)
            ->whereIn('status', [
                WordpressDeployment::STATUS_QUEUED,
                WordpressDeployment::STATUS_RUNNING,
                WordpressDeployment::STATUS_SUCCEEDED,
            ])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }
}
