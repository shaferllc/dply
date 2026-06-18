<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\WorkspaceInsightsDetailModalTest;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Insights\Services\Contracts\InsightFixActionInterface;
use App\Modules\Insights\Services\FixResult;

/**
 * Minimal stand-in so config('insights.insights.[key].fix.handler') resolves
 * to a class that satisfies the InsightFixActionInterface contract during
 * preflight checks. The job is faked in these tests so apply() never runs.
 */
class StubFixHandler implements InsightFixActionInterface
{
    public function preflight(Server $server, ?Site $site, InsightFinding $finding, array $params): ?string
    {
        return null;
    }

    public function apply(Server $server, ?Site $site, InsightFinding $finding, array $params, ?callable $onOutput = null): FixResult
    {
        return FixResult::success('stub-applied');
    }
}
