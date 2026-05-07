<?php

namespace App\Services\Insights\Contracts;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\FixResult;

/**
 * Companion interface for fix actions that took a backup and can undo themselves.
 * The job uses `instanceof` to gate the revert flow; UI gates the "Revert" button
 * on `$finding->meta['backup_path']` being present.
 */
interface RevertableInsightFixActionInterface
{
    /**
     * Restore the prior on-disk state captured during apply. Should never throw —
     * all failures (missing backup file, reload failure, etc.) go in the FixResult.
     *
     * @param  array<string, mixed>  $params
     * @param  (callable(string $type, string $chunk): void)|null  $onOutput
     *     Optional streaming hook for live banner output. See {@see InsightFixActionInterface::apply}.
     */
    public function revert(Server $server, ?Site $site, InsightFinding $finding, array $params, ?callable $onOutput = null): FixResult;
}
