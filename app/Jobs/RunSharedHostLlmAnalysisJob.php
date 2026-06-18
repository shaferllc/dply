<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiAdvisorRun;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\Ai\Services\AiAdvisorRunRecorder;
use App\Modules\Ai\Services\LlmSynthesizer;
use App\Support\Servers\SharedHostLlmAdvisor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunSharedHostLlmAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(
        public string $runId,
        public string $organizationId,
        public string $serverId,
        public ?string $userId = null,
    ) {}

    public function handle(
        AiAdvisorRunRecorder $recorder,
        SharedHostLlmAdvisor $advisor,
        LlmSynthesizer $synthesizer,
    ): void {
        $run = AiAdvisorRun::query()->find($this->runId);
        if ($run === null || ! $run->isPending()) {
            return;
        }

        $organization = Organization::query()->find($this->organizationId);
        $server = Server::find($this->serverId);
        if ($organization === null || $server === null) {
            $recorder->fail($run, 'Organization or server not found.');

            return;
        }

        if (! $advisor->canRun($organization)) {
            $recorder->fail($run, 'LLM synthesis is not enabled for this organization.');

            return;
        }

        $context = is_array($run->request_context) ? $run->request_context : [];
        if ($context === []) {
            $recorder->fail($run, 'Shared host report context missing.');

            return;
        }

        try {
            $result = $synthesizer->synthesizeSharedHost($context);
            $recorder->complete($run, $result);
        } catch (\Throwable $exception) {
            Log::warning('Shared Host LLM analysis failed', [
                'run_id' => $run->id,
                'server_id' => $server->id,
                'message' => $exception->getMessage(),
            ]);
            $recorder->fail($run, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $reportPayload
     */
    public static function dispatchForServer(
        Organization $organization,
        Server $server,
        ?User $user,
        AiAdvisorRunRecorder $recorder,
        array $reportPayload,
    ): AiAdvisorRun {
        $run = $recorder->start(
            organization: $organization,
            feature: AiAdvisorRun::FEATURE_SHARED_HOST,
            subject: $server,
            user: $user,
            requestContext: $reportPayload,
        );

        self::dispatch($run->id, $organization->id, $server->id, $user?->id);

        return $run;
    }
}
