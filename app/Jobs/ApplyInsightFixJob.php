<?php

namespace App\Jobs;

use App\Models\InsightFinding;
use App\Models\User;
use App\Services\Insights\Contracts\InsightFixActionInterface;
use App\Services\Insights\InsightRunCoordinator;
use App\Services\Insights\InsightsBannerStream;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApplyInsightFixJob implements ShouldQueue
{
    use Queueable;

    /**
     * Worst-case bound: the apt-security-updates handler ({@see ApplyPackageSecurityUpdatesFixAction})
     * uses a 600s SSH timeout for `unattended-upgrade` runs on busy boxes; add the recheck-runner
     * pass and a small slack for meta writes / banner flushes. The Horizon supervisor `timeout`
     * must be ≥ this value or the worker process is killed before the job's own timeout fires —
     * see config/horizon.php and the HORIZON_*_JOB_TIMEOUT env vars.
     */
    public int $timeout = 700;

    public function __construct(
        public int $insightFindingId,
        public string $userId,
        public ?string $runId = null,
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

        $banner = $this->runId !== null ? $this->openBanner($finding) : null;

        $banner?->append(sprintf(
            '> Applying fix for [%s] on %s …',
            $finding->insight_key,
            $finding->site !== null
                ? sprintf('site %s', $finding->site->name)
                : $server->getSshConnectionString(),
        ));

        $key = $finding->insight_key;
        $def = config('insights.insights.'.$key);
        $fix = is_array($def) ? ($def['fix'] ?? null) : null;
        $handlerClass = is_array($fix) ? ($fix['handler'] ?? null) : null;
        if (! is_string($handlerClass) || ! class_exists($handlerClass)) {
            $banner?->append('> ERROR: fix handler class missing.');
            $banner?->fail('fix_handler_missing');
            $this->recordFailure($finding, $user, 'fix_handler_missing', null);

            return;
        }

        $handler = app($handlerClass);
        if (! $handler instanceof InsightFixActionInterface) {
            $banner?->append('> ERROR: fix handler does not implement the expected contract.');
            $banner?->fail('fix_handler_invalid');
            $this->recordFailure($finding, $user, 'fix_handler_invalid', null);

            return;
        }

        if ((bool) ($fix['mutates_config'] ?? false)) {
            $org = $server->organization;
            $prefs = is_array($org?->insights_preferences ?? null) ? $org->insights_preferences : [];
            $allow = array_key_exists('allow_config_mutation', $prefs)
                ? (bool) $prefs['allow_config_mutation']
                : true;
            if (! $allow) {
                $banner?->append('> Refused — config mutation disabled by organization preference.');
                $banner?->refuse('config_mutation_disabled_by_org');
                $this->recordRefusal($finding, $user, 'config_mutation_disabled_by_org');

                return;
            }
        }

        $params = is_array($fix['params'] ?? null) ? $fix['params'] : [];
        $site = $finding->site;

        $banner?->append('> Preflighting…');
        $refusal = $handler->preflight($server, $site, $finding, $params);
        if ($refusal !== null) {
            $banner?->append('> Refused — '.Str::limit($refusal, 400));
            $banner?->refuse($refusal);
            $this->recordRefusal($finding, $user, $refusal);

            return;
        }

        $banner?->append('> Preflight ok — applying…');

        // Streaming hook so long-running handlers (apt updates etc.) can tee SSH stdout
        // into the banner buffer in real time. The chunk arrives as a single line or a
        // multi-line block depending on Symfony's pipe drainage; appendBlock splits it
        // and trims empty lines so the buffer stays readable.
        $stream = $banner === null
            ? null
            : function (string $type, string $chunk) use ($banner): void {
                $banner->appendBlock($chunk);
            };

        $result = $handler->apply($server, $site, $finding, $params, $stream);

        if (! $result->ok) {
            $reason = $result->errorMessage ?? 'fix_failed';
            $banner?->append('> Apply failed — '.Str::limit($reason, 400));
            if ($result->output !== '') {
                $banner?->appendBlock($result->output);
            }
            $banner?->fail($reason);
            $this->recordFailure($finding, $user, $reason, $result->output);

            return;
        }

        $banner?->append('> Apply ok.');
        if ($result->output !== '') {
            $banner?->appendBlock($result->output);
        }

        if ($finding->kind === InsightFinding::KIND_SUGGESTION) {
            $banner?->append('> Done — suggestion applied and finding resolved.');
            $banner?->succeed();
            $this->markApplied($finding, $user, $result->output);

            return;
        }

        $banner?->append(sprintf('> Re-running originating runner [%s] …', $key));
        if ($site === null) {
            $coordinator->runForServer($server, $key);
        } else {
            $coordinator->runForSite($site, $key);
        }

        $finding->refresh();
        if ($finding->status === InsightFinding::STATUS_RESOLVED) {
            $banner?->append('> Recheck: cleared. Finding resolved.');
            $banner?->succeed();
            $this->annotateResolvedByFix($finding, $user, $result->output);

            return;
        }

        $banner?->append('> Recheck: still failing — fix ran but did not clear the condition.');
        $banner?->fail('recheck_still_failing');
        $this->recordFailure(
            $finding,
            $user,
            'recheck_still_failing',
            $result->output,
        );
    }

    private function openBanner(InsightFinding $finding): InsightsBannerStream
    {
        $entity = $finding->site ?? $finding->server;

        return new InsightsBannerStream(
            entity: $entity,
            runId: (string) $this->runId,
            findingId: $finding->id,
            statusKeyConfig: 'insights_workspace.meta_fix_status_key',
            finishedKeyConfig: 'insights_workspace.meta_fix_finished_at_key',
            errorKeyConfig: 'insights_workspace.meta_fix_error_key',
            cachePrefixConfig: 'insights_workspace.fix_output_cache_key_prefix',
            cacheTtlConfig: 'insights_workspace.fix_output_cache_ttl_seconds',
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

