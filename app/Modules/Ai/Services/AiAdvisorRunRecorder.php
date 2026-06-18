<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use App\Models\AiAdvisorRun;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class AiAdvisorRunRecorder
{
    /**
     * @param  array<string, mixed> $requestContext
     */
    public function start(
        Organization $organization,
        string $feature,
        ?Model $subject,
        ?User $user,
        array $requestContext,
    ): AiAdvisorRun {
        return AiAdvisorRun::query()->create([
            'organization_id' => $organization->id,
            'feature' => $feature,
            'status' => AiAdvisorRun::STATUS_PENDING,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'triggered_by_user_id' => $user?->id,
            'request_context' => $requestContext,
        ]);
    }

    public function complete(AiAdvisorRun $run, LlmSynthesisResult $result): AiAdvisorRun
    {
        $run->update([
            'status' => AiAdvisorRun::STATUS_COMPLETED,
            'response' => $result->toArray(),
            'prompt_tokens' => $result->promptTokens,
            'completion_tokens' => $result->completionTokens,
            'latency_ms' => $result->latencyMs,
            'finished_at' => now(),
        ]);

        return $run->fresh();
    }

    public function fail(AiAdvisorRun $run, string $message): AiAdvisorRun
    {
        $run->update([
            'status' => AiAdvisorRun::STATUS_FAILED,
            'error_message' => $message,
            'finished_at' => now(),
        ]);

        return $run->fresh();
    }

    public function latestForSubject(Model $subject, string $feature): ?AiAdvisorRun
    {
        return AiAdvisorRun::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('feature', $feature)
            ->orderByDesc('created_at')
            ->first();
    }
}
