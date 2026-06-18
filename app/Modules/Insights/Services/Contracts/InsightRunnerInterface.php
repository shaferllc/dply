<?php

namespace App\Modules\Insights\Services\Contracts;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Insights\Services\InsightCandidate;

interface InsightRunnerInterface
{
    /**
     * @return list<InsightCandidate>
     * @param  array<string, mixed> $parameters
     */
    public function run(Server $server, ?Site $site, array $parameters): array;
}
