<?php

namespace App\Services\Insights\Contracts;

use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\InsightCandidate;

interface InsightRunnerInterface
{
    /**
     * @return list<InsightCandidate>
     */
    public function run(Server $server, ?Site $site, array $parameters): array;
}
