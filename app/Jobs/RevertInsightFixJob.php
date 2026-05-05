<?php

namespace App\Jobs;

use App\Models\InsightFinding;
use App\Models\User;
use App\Services\Insights\Contracts\RevertableInsightFixActionInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RevertInsightFixJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public function __construct(
        public int $insightFindingId,
        public string $userId,
    ) {}

    public function handle(): void
    {
        $finding = InsightFinding::query()->with(['server', 'site'])->find($this->insightFindingId);
        $user = User::query()->find($this->userId);
        if ($finding === null || $user === null) {
            return;
        }

        $server = $finding->server;
        if ($server === null || ! $user->can('update', $server)) {
            return;
        }

        if (empty($finding->meta['backup_path'] ?? null)) {
            $this->stampFailure($finding, $user, 'no_backup_path');

            return;
        }

        $key = $finding->insight_key;
        $def = config('insights.insights.'.$key);
        $fix = is_array($def) ? ($def['fix'] ?? null) : null;
        $handlerClass = is_array($fix) ? ($fix['handler'] ?? null) : null;
        if (! is_string($handlerClass) || ! class_exists($handlerClass)) {
            $this->stampFailure($finding, $user, 'fix_handler_missing');

            return;
        }

        $handler = app($handlerClass);
        if (! $handler instanceof RevertableInsightFixActionInterface) {
            $this->stampFailure($finding, $user, 'handler_not_revertable');

            return;
        }

        $params = is_array($fix['params'] ?? null) ? $fix['params'] : [];
        $result = $handler->revert($server, $finding->site, $finding, $params);

        if (! $result->ok) {
            $this->stampFailure($finding, $user, $result->errorMessage ?? 'revert_failed');

            return;
        }

        $org = $server->organization;
        if ($org !== null) {
            audit_log($org, $user, 'insight.fix_reverted', $server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $key,
            ]);
        }
    }

    private function stampFailure(InsightFinding $finding, User $user, string $reason): void
    {
        Log::warning('insight.revert_failed', [
            'finding_id' => $finding->id,
            'reason' => $reason,
        ]);
        $meta = is_array($finding->meta) ? $finding->meta : [];
        $meta['revert_failed_at'] = now()->toIso8601String();
        $meta['revert_failed_by'] = $user->id;
        $meta['revert_failure_reason'] = $reason;
        $finding->forceFill(['meta' => $meta])->save();
    }
}
