<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiAdvisorRun;
use App\Models\Organization;
use Illuminate\Support\Facades\RateLimiter;

final class AiRateLimiter
{
    public function tooManyAttempts(Organization $organization): bool
    {
        $limit = (int) config('dply_ai.rate_limits.per_org_per_hour', 30);
        $key = 'ai-advisor:org:'.$organization->id;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return true;
        }

        RateLimiter::hit($key, 3600);

        return false;
    }

    public function remaining(Organization $organization): int
    {
        $limit = (int) config('dply_ai.rate_limits.per_org_per_hour', 30);
        $key = 'ai-advisor:org:'.$organization->id;

        return RateLimiter::remaining($key, $limit);
    }

    public function recentRunCount(Organization $organization, string $feature, int $minutes = 5): int
    {
        return AiAdvisorRun::query()
            ->where('organization_id', $organization->id)
            ->where('feature', $feature)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }
}
