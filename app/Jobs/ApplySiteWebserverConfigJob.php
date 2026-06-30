<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Models\SiteAccessGatePassword;
use App\Models\SiteBasicAuthUser;
use App\Services\Sites\SiteWebserverConfigApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApplySiteWebserverConfigJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    /**
     * Retry transient apply failures (SSH blips, mid-reload races). A rapid
     * maintenance suspend→resume toggle previously left the box broken when a
     * single attempt failed and nothing retried or surfaced it.
     */
    public int $tries = 3;

    public function __construct(
        public string $siteId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
        public bool $recordApplied = false,
    ) {}

    /** Auto-expire the unique lock so a lost/killed run can't wedge it forever. */
    public int $uniqueFor = 300;

    /**
     * Back off between retries (seconds). Gives a flapping nginx/SSH state a
     * moment to settle before re-testing and reloading.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 20];
    }

    public function uniqueId(): string
    {
        return 'console-action:webserver_config:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'webserver_config';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SiteWebserverConfigApplier $applier): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        $applyStartedAt = now();
        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            // The applier streams progress lines through `$emit`; the emitter writes
            // each one to the console_actions row's JSON output column.
            $applier->apply($site, $emit);

            // Hard-delete any basic-auth credentials that were marked for removal
            // before this apply started. The htpasswd files we just wrote already
            // exclude these rows — the gate is gone, so it's safe to drop the
            // tracking rows. We restrict to rows stamped *before* this run so a
            // row stamped during the apply (which didn't make it into the htpasswd
            // we wrote) survives until the next apply rewrites the file.
            SiteBasicAuthUser::query()
                ->where('site_id', $site->id)
                ->whereNotNull('pending_removal_at')
                ->where('pending_removal_at', '<=', $applyStartedAt)
                ->delete();

            SiteAccessGatePassword::query()
                ->where('site_id', $site->id)
                ->whereNotNull('pending_removal_at')
                ->where('pending_removal_at', '<=', $applyStartedAt)
                ->delete();

            // Editor-driven apply: stamp last-applied checksums + record an
            // "Applied to server" revision so the async path keeps the same
            // bookkeeping the old synchronous apply had. Other callers (tenant
            // routing, basic-auth, suspend) leave this off — they're re-applying
            // managed config, not a user edit.
            if ($this->recordApplied) {
                $emit->step('webserver', 'recording revision');
                $user = $this->userId !== null && $this->userId !== ''
                    ? \App\Models\User::find($this->userId)
                    : null;
                app(\App\Services\Sites\WebserverConfig\SiteWebserverConfigEditorService::class)
                    ->recordApplied($site->fresh(['server']), $user);

                $org = $site->organization;
                if ($org) {
                    audit_log($org, $user, 'site.webserver_config.applied', $site->fresh(), null, [
                        'webserver' => $site->webserver(),
                    ]);
                }
            }

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage());
            $this->failConsoleAction($e->getMessage());

            Log::warning('ApplySiteWebserverConfigJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
