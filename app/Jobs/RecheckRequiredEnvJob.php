<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Services\Sites\RequiredEnvEvaluator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-evaluates the required-env deploy gate against the live server .env and
 * updates meta.deploy_blocked_env accordingly — the non-destructive way to
 * clear a stale "Deploy needs N environment variables" banner once the
 * operator has actually set the vars, without running a full deploy.
 *
 * Reuses {@see RequiredEnvEvaluator} (the same code the deploy gate runs), so
 * the banner reflects exactly what the next deploy would decide. SSH read runs
 * off-request in a worker; progress streams to a console_actions row.
 */
class RecheckRequiredEnvJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'console-action:env_recheck:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'env_recheck';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(RequiredEnvEvaluator $evaluator): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $emit->step('recheck', __('Reading .env from :path', ['path' => $site->effectiveEnvFilePath()]));

            $missing = $evaluator->evaluateAndRecord($site);

            if ($missing === null) {
                $emit->success(__('Nothing to check — the required-env gate does not apply to this site.'));
            } elseif ($missing === []) {
                $emit->success(__('All required variables are set. Deploys are unblocked.'));
            } else {
                $names = array_map(static fn (array $entry): string => (string) $entry['key'], $missing);
                $emit->success(__('Still missing :count: :keys', [
                    'count' => count($names),
                    'keys' => implode(', ', $names),
                ]));
            }

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'recheck');
            $this->failConsoleAction($e->getMessage());

            Log::warning('RecheckRequiredEnvJob failed', [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
