<?php

namespace App\Jobs;

use App\Models\InsightFinding;
use App\Models\User;
use App\Services\Insights\Contracts\RevertableInsightFixActionInterface;
use App\Services\Insights\InsightsBannerStream;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RevertInsightFixJob implements ShouldQueue
{
    use Queueable;

    /**
     * Reverts restore from a backup file and (sometimes) restart a service — typically much
     * faster than the apply path, but bounded generously so a slow box doesn't strand the run.
     */
    public int $timeout = 300;

    public function __construct(
        public int $insightFindingId,
        public string $userId,
        public ?string $runId = null,
    ) {}

    public function handle(): void
    {
        $finding = InsightFinding::query()->with(['server', 'site'])->find($this->insightFindingId);
        $user = User::find($this->userId);
        if ($finding === null || $user === null) {
            return;
        }

        $server = $finding->server;
        if (! $user->can('update', $server)) {
            return;
        }

        $banner = $this->runId !== null ? $this->openBanner($finding) : null;

        $banner?->append(sprintf(
            '> Reverting fix for [%s] on %s …',
            $finding->insight_key,
            $finding->site !== null
                ? sprintf('site %s', $finding->site->name)
                : $server->getSshConnectionString(),
        ));

        if (empty($finding->meta['backup_path'] ?? null)) {
            $banner?->append('> ERROR: no backup path recorded — cannot revert.');
            $banner?->fail('no_backup_path');
            $this->stampFailure($finding, $user, 'no_backup_path');

            return;
        }

        $key = $finding->insight_key;
        $def = config('insights.insights.'.$key);
        $fix = is_array($def) ? ($def['fix'] ?? null) : null;
        $handlerClass = is_array($fix) ? ($fix['handler'] ?? null) : null;
        if (! is_string($handlerClass) || ! class_exists($handlerClass)) {
            $banner?->append('> ERROR: fix handler class missing.');
            $banner?->fail('fix_handler_missing');
            $this->stampFailure($finding, $user, 'fix_handler_missing');

            return;
        }

        $handler = app($handlerClass);
        if (! $handler instanceof RevertableInsightFixActionInterface) {
            $banner?->append('> ERROR: handler does not support revert.');
            $banner?->fail('handler_not_revertable');
            $this->stampFailure($finding, $user, 'handler_not_revertable');

            return;
        }

        $banner?->append(sprintf('> Restoring backup from %s …', (string) $finding->meta['backup_path']));

        $stream = $banner === null
            ? null
            : function (string $type, string $chunk) use ($banner): void {
                $banner->appendBlock($chunk);
            };

        $params = is_array($fix['params'] ?? null) ? $fix['params'] : [];
        $result = $handler->revert($server, $finding->site, $finding, $params, $stream);

        if (! $result->ok) {
            $reason = $result->errorMessage ?? 'revert_failed';
            $banner?->append('> Revert failed — '.Str::limit($reason, 400));
            if ($result->output !== '') {
                $banner?->appendBlock($result->output);
            }
            $banner?->fail($reason);
            $this->stampFailure($finding, $user, $reason);

            return;
        }

        $banner?->append('> Revert ok.');
        if ($result->output !== '') {
            $banner?->appendBlock($result->output);
        }

        $org = $server->organization;
        if ($org !== null) {
            audit_log($org, $user, 'insight.fix_reverted', $server, null, [
                'finding_id' => $finding->id,
                'insight_key' => $key,
            ]);
        }

        $banner?->append('> Done.');
        $banner?->succeed();
    }

    private function openBanner(InsightFinding $finding): InsightsBannerStream
    {
        $entity = $finding->site ?? $finding->server;

        return new InsightsBannerStream(
            entity: $entity,
            runId: (string) $this->runId,
            findingId: $finding->id,
            statusKeyConfig: 'insights_workspace.meta_revert_status_key',
            finishedKeyConfig: 'insights_workspace.meta_revert_finished_at_key',
            errorKeyConfig: 'insights_workspace.meta_revert_error_key',
            cachePrefixConfig: 'insights_workspace.revert_output_cache_key_prefix',
            cacheTtlConfig: 'insights_workspace.revert_output_cache_ttl_seconds',
        );
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
