<?php

namespace App\Jobs;

use App\Models\InsightFinding;
use App\Models\User;
use App\Services\Insights\Contracts\InsightFixActionInterface;
use App\Services\Insights\InsightRunCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ApplyInsightFixJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public function __construct(
        public int $insightFindingId,
        public string $userId,
    ) {}

    public function handle(InsightRunCoordinator $coordinator): void
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
        $def = config('insights.insights.'.$key);
        $fix = is_array($def) ? ($def['fix'] ?? null) : null;
        $handlerClass = is_array($fix) ? ($fix['handler'] ?? null) : null;
        if (! is_string($handlerClass) || ! class_exists($handlerClass)) {
            $this->recordFailure($finding, $user, 'fix_handler_missing', null);

            return;
        }

        $handler = app($handlerClass);
        if (! $handler instanceof InsightFixActionInterface) {
            $this->recordFailure($finding, $user, 'fix_handler_invalid', null);

            return;
        }

        $params = is_array($fix['params'] ?? null) ? $fix['params'] : [];
        $site = $finding->site;

        $refusal = $handler->preflight($server, $site, $finding, $params);
        if ($refusal !== null) {
            $this->recordRefusal($finding, $user, $refusal);

            return;
        }

        $result = $handler->apply($server, $site, $finding, $params);

        if (! $result->ok) {
            $this->recordFailure($finding, $user, $result->errorMessage ?? 'fix_failed', $result->output);

            return;
        }

        if ($finding->kind === InsightFinding::KIND_SUGGESTION) {
            $this->markApplied($finding, $user, $result->output);

            return;
        }

        // Problem: re-run the originating runner. The recorder closes the finding when the
        // candidate goes away; if it's still open after the recheck, the action ran but
        // didn't actually clear the condition — surface that as a failed fix.
        if ($site === null) {
            $coordinator->runForServer($server, $key);
        } else {
            $coordinator->runForSite($site, $key);
        }

        $finding->refresh();
        if ($finding->status === InsightFinding::STATUS_RESOLVED) {
            $this->annotateResolvedByFix($finding, $user, $result->output);

            return;
        }

        $this->recordFailure(
            $finding,
            $user,
            'recheck_still_failing',
            $result->output,
        );
    }

    private function markApplied(InsightFinding $finding, User $user, string $output): void
    {
        $meta = is_array($finding->meta) ? $finding->meta : [];
        $meta['fix_applied_at'] = now()->toIso8601String();
        $meta['fix_applied_by'] = $user->id;
        $meta['fix_output'] = $output;
        $finding->forceFill([
            'meta' => $meta,
            'status' => InsightFinding::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();

        $this->auditLog($finding, $user, 'insight.fix_applied');
    }

    private function annotateResolvedByFix(InsightFinding $finding, User $user, string $output): void
    {
        $meta = is_array($finding->meta) ? $finding->meta : [];
        $meta['fix_applied_at'] = now()->toIso8601String();
        $meta['fix_applied_by'] = $user->id;
        $meta['fix_output'] = $output;
        $finding->forceFill(['meta' => $meta])->save();

        $this->auditLog($finding, $user, 'insight.fix_applied');
    }

    private function recordFailure(InsightFinding $finding, User $user, string $reason, ?string $output): void
    {
        Log::warning('insight.fix_failed', [
            'finding_id' => $finding->id,
            'reason' => $reason,
        ]);
        $meta = is_array($finding->meta) ? $finding->meta : [];
        $meta['fix_failed_at'] = now()->toIso8601String();
        $meta['fix_failed_by'] = $user->id;
        $meta['fix_failure_reason'] = $reason;
        if ($output !== null && $output !== '') {
            $meta['fix_output'] = $output;
        }
        $finding->forceFill(['meta' => $meta])->save();

        $this->auditLog($finding, $user, 'insight.fix_failed', ['reason' => $reason]);
    }

    private function recordRefusal(InsightFinding $finding, User $user, string $reason): void
    {
        $meta = is_array($finding->meta) ? $finding->meta : [];
        $meta['fix_refused_at'] = now()->toIso8601String();
        $meta['fix_refused_by'] = $user->id;
        $meta['fix_refusal_reason'] = $reason;
        $finding->forceFill(['meta' => $meta])->save();

        $this->auditLog($finding, $user, 'insight.fix_refused', ['reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function auditLog(InsightFinding $finding, User $user, string $action, array $extra = []): void
    {
        $server = $finding->server;
        $org = $server?->organization;
        if ($org === null || $server === null) {
            return;
        }
        audit_log($org, $user, $action, $server, null, array_merge([
            'finding_id' => $finding->id,
            'insight_key' => $finding->insight_key,
        ], $extra));
    }
}
