<?php

namespace App\Jobs;

use App\Models\InsightFinding;
use App\Models\User;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ApplyInsightFixJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(
        public int $insightFindingId,
        public int $userId,
    ) {}

    public function handle(ExecuteRemoteTaskOnServer $remote): void
    {
        $finding = InsightFinding::query()->with(['server', 'site'])->find($this->insightFindingId);
        $user = User::query()->find($this->userId);
        if ($finding === null || $user === null || ! $finding->isOpen()) {
            return;
        }

        $server = $finding->server;
        if (! $user->can('update', $server)) {
            return;
        }

        $key = $finding->insight_key;
        $fix = config('insights.insights.'.$key.'.fix');
        $action = is_array($fix) ? ($fix['action'] ?? null) : null;

        if ($action !== 'supervisor_start') {
            return;
        }

        if ($key !== 'supervisor_running') {
            return;
        }

        $inline = <<<'BASH'
if systemctl start supervisor 2>/dev/null || systemctl start supervisord 2>/dev/null; then
  echo "started"
elif service supervisor start 2>/dev/null; then
  echo "started"
else
  echo "failed"
  exit 1
fi
BASH;

        try {
            $remote->runInlineBash($server, 'insight-fix-supervisor-start', $inline, 60, true);
        } catch (\Throwable $e) {
            Log::warning('insight.fix_failed', [
                'finding_id' => $finding->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $meta = $finding->meta ?? [];
        $meta['fix_applied_at'] = now()->toIso8601String();
        $meta['fix_applied_by'] = $user->id;
        $finding->forceFill([
            'meta' => $meta,
            'status' => InsightFinding::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();

        $org = $server->organization;
        if ($org) {
            audit_log($org, $user, 'insight.fix_applied', $server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $key,
                'action' => $action,
            ]);
        }
    }
}
