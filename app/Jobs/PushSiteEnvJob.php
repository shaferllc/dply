<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Services\Sites\SiteEnvPusher;
use App\Services\Sites\SiteEnvRuntimeApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Wraps {@see SiteEnvPusher::push()} in a console-action job so progress
 * streams into the page-top banner — same pattern as Sync and Load.
 *
 * One-in-flight-per-site via {@see ShouldBeUnique}: rapid-fire mutations
 * (e.g. bulk paste followed by a single edit) coalesce naturally — the
 * second dispatch is rejected by the queue uniqueness guard, and the
 * single in-flight job reads the latest cache state when it runs, so
 * every change still lands on the server.
 *
 * Errors fail the run and are surfaced in the banner; the editable cache
 * is preserved so the operator can retry from the manual Push button.
 */
class PushSiteEnvJob implements ShouldBeUnique, ShouldQueue
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
        return 'console-action:env_push:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'env_push';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SiteEnvPusher $pusher): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $emit->step('push', __('Resolving server connection'));
            $emit->step('push', __('Writing .env to :path', ['path' => $site->effectiveEnvFilePath()]));

            $path = $pusher->push($site);

            // Make the write actually take effect on the running app: rebuild
            // cached config + reload (no-op for sites that read .env live). The
            // applier guards against applying a broken env — if it refuses, the
            // .env is still saved (so a mid-edit save isn't blocked) but the
            // last-good cached config keeps serving; surface that to the operator.
            try {
                $emit->step('push', __('Applying environment to the running app'));
                app(SiteEnvRuntimeApplier::class)->apply($site);
                $emit->success(__('.env written to :path and applied', ['path' => $path]));
            } catch (\Throwable $applyEx) {
                $emit->success(__('.env written to :path — not applied: :why', [
                    'path' => $path,
                    'why' => $applyEx->getMessage(),
                ]));
                Log::warning('PushSiteEnvJob: env written but not applied', [
                    'site_id' => $this->siteId,
                    'reason' => $applyEx->getMessage(),
                ]);
            }

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'push');
            $this->failConsoleAction($e->getMessage());

            Log::warning('PushSiteEnvJob failed', [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
