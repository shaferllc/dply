<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Modules\Deploy\Services\SiteBindingManager;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\SiteEnvReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reads the live `.env` from the site's host over SSH and writes it into
 * the encrypted `sites.env_file_content` cache. The Environment settings
 * page consults that cache on render, so this job is what turns "what's
 * in the page" back into "what the app on the server actually reads."
 *
 * Streams progress to a console_actions row so the page-top banner shows
 * the scan happening live, exactly like SyncBasicAuthFromServerJob.
 *
 * One-in-flight-per-(site, kind) is enforced via {@see ShouldBeUnique};
 * a second click while a sync is running just returns the same job.
 *
 * Parser warnings (lines that look invalid) are emitted as `warn` lines but
 * the job still succeeds — the server's .env is the truth even if it has
 * shapes our parser doesn't understand. The operator sees the warnings in
 * the banner and can decide whether to re-edit.
 */
class SyncEnvFromServerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
        public ?string $userId = null,
        public ?string $seededConsoleRunId = null,
    ) {}

    /** Auto-expire the unique lock so a lost/killed run can't wedge it forever. */
    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'console-action:env_sync:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'env_sync';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SiteEnvReader $reader, DotEnvFileParser $parser): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $emit->step('sync', __('Resolving server connection'));
            $emit->step('sync', __('Reading .env from :path', ['path' => $site->effectiveEnvFilePath()]));

            $raw = $reader->read($site);
            $parsed = $parser->parse($raw);

            foreach ($parsed['errors'] as $error) {
                $emit($error, 'warn', 'sync');
            }

            $site->forceFill([
                'env_file_content' => $raw,
                'env_synced_at' => now(),
                'env_cache_origin' => 'server',
            ])->save();

            // The raw server .env re-introduces keys an attached binding owns
            // (REDIS_*, MAIL_*, DB_*, …) as loose rows. Re-adopt so they stay
            // managed under their resource instead of bouncing into the
            // editable list after every sync.
            $reAdopted = app(SiteBindingManager::class)->reAdoptAll($site);
            if ($reAdopted !== []) {
                $emit->step('sync', sprintf('Re-adopted %d key(s) into connected resources.', count($reAdopted)));
            }

            $count = count($parsed['variables']);
            if ($count === 0 && trim($raw) === '') {
                $emit->success(__('No .env on server — cache cleared.'));
            } else {
                $emit->success(sprintf('Imported %d key(s) from server.', $count));
            }

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'sync');
            $this->failConsoleAction($e->getMessage());

            Log::warning('SyncEnvFromServerJob failed', [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
