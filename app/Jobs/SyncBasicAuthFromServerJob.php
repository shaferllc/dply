<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Services\Sites\SiteBasicAuthDiscovery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Walks the site's repo on the server, finds every .htpasswd, and imports any
 * user entries that aren't already in the database. Streams progress to a
 * console_actions row so the basic-auth tab's banner shows the scan happening
 * live.
 *
 * One-in-flight-per-(site, kind) is enforced via {@see ShouldBeUnique}; a
 * second click while a sync is running just returns the same job.
 */
class SyncBasicAuthFromServerJob implements ShouldBeUnique, ShouldQueue
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
        return 'console-action:basic_auth_sync:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'basic_auth_sync';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SiteBasicAuthDiscovery $discovery): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $emit->step('sync', __('Resolving server connection'));
            $emit->step('sync', __('Scanning :path for .htpasswd files', ['path' => $site->effectiveRepositoryPath()]));

            $rows = $discovery->discover($site);

            if ($rows->isEmpty()) {
                $emit->success(__('No .htpasswd files found.'));
                $this->completeConsoleAction();

                return;
            }

            $existingUsernames = $site->basicAuthUsers()->pluck('username')->all();
            $sortBase = (int) ($site->basicAuthUsers()->max('sort_order') ?? 0);
            $created = 0;
            $skipped = 0;
            $sources = [];

            foreach ($rows as $row) {
                $username = trim((string) $row['username']);
                $hash = trim((string) $row['password_hash']);
                if ($username === '' || $hash === '') {
                    continue;
                }

                $sources[$row['discovered_file_path']] = true;

                if (in_array($username, $existingUsernames, true)) {
                    $emit('skip duplicate user '.$username.' (already tracked)', 'warn', 'sync');
                    $skipped++;

                    continue;
                }

                SiteBasicAuthUser::query()->create([
                    'site_id' => $site->id,
                    'username' => $username,
                    'password_hash' => $hash,
                    'path' => SiteBasicAuthUser::normalizePath($row['path'] ?? '/'),
                    'source_file_path' => $row['source_file_path'] ?? null,
                    'sort_order' => ++$sortBase,
                ]);
                $existingUsernames[] = $username;
                $created++;
                $emit('imported '.$username.' from '.$row['discovered_file_path'], 'info', 'sync');
            }

            // Wording matters: a "0 imported, N skipped" run isn't a no-op — it's
            // the operator's confirmation that on-disk and in-DB state already
            // match. Phrase it that way so a clean run doesn't look like a bug.
            if ($created === 0 && $skipped > 0) {
                $emit->success(sprintf(
                    'already in sync — %d credential(s) on disk match the database',
                    $skipped,
                ));
            } elseif ($created === 0 && $skipped === 0) {
                $emit->success('no new credentials found');
            } else {
                $emit->success(sprintf(
                    'imported %d new credential(s); %d already tracked; from %d file(s)',
                    $created,
                    $skipped,
                    count($sources),
                ));
            }

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'sync');
            $this->failConsoleAction($e->getMessage());

            Log::warning('SyncBasicAuthFromServerJob failed', [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
