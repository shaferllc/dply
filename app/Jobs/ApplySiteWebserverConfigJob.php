<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Services\Sites\SiteWebserverConfigApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApplySiteWebserverConfigJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
        public ?string $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'console-action:webserver_config:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
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
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $applyStartedAt = now();
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
