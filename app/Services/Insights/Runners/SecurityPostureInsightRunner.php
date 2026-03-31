<?php

namespace App\Services\Insights\Runners;

use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;

/**
 * Placeholder for Composer/npm audit + TLS posture. Returns no findings until audits are wired.
 */
class SecurityPostureInsightRunner implements InsightRunnerInterface
{
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        return [];
    }
}
