<?php

namespace App\Services\Insights\Contracts;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\FixResult;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

interface InsightFixActionInterface
{
    /**
     * Block the apply if a precondition isn't met. Return null when safe to proceed,
     * or a short human-readable reason string when refusing. The reason is surfaced
     * in the apply-fix flow so the user understands why nothing happened.
     *
     * @param  array<string, mixed> $params  Per-fix parameters from config.
     */
    public function preflight(Server $server, ?Site $site, InsightFinding $finding, array $params): ?string;

    /**
     * Execute the fix. Implementations must capture stdout/stderr into the FixResult
     * (size-bounded by the implementation) and never throw — all failure modes go in
     * the FixResult.
     *
     * @param  array<string, mixed> $params  Per-fix parameters from config.
     * @param  (callable(string $type, string $chunk): void)|null  $onOutput
     *                                                                        Optional streaming hook. When supplied, long-running handlers should plumb it
     *                                                                        through to the SSH layer (e.g. {@see ExecuteRemoteTaskOnServer::runInlineBashWithOutputCallback})
     *                                                                        so the workspace banner can show progress in real time. Handlers that complete
     *                                                                        in milliseconds may safely ignore it.
     */
    public function apply(Server $server, ?Site $site, InsightFinding $finding, array $params, ?callable $onOutput = null): FixResult;
}
