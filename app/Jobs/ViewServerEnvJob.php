<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
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
 * Read-only counterpart to {@see SyncEnvFromServerJob}: reads the live `.env`
 * from the host and streams a key inventory to a console_actions row WITHOUT
 * writing anything back into dply's cache. This is the safe "let me just see
 * what's actually on the server" view — so an operator chasing a blocked-env
 * banner can confirm which keys exist (and which are blank) without the
 * destructive overwrite that Sync does.
 *
 * Values are masked (length only) — the inventory answers "is this key set?"
 * which is all the gate cares about, and avoids persisting secrets into the
 * console output log.
 */
class ViewServerEnvJob implements ShouldBeUnique, ShouldQueue
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
        return 'console-action:env_view:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'env_view';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SiteEnvReader $reader, DotEnvFileParser $parser): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $this->bindConsoleRunId($this->seededConsoleRunId);
        $emit = $this->beginConsoleAction();

        try {
            $emit->step('view', __('Reading .env from :path', ['path' => $site->effectiveEnvFilePath()]));

            $raw = $reader->read($site);
            $parsed = $parser->parse($raw);
            $variables = $parsed['variables'];

            if ($variables === [] && trim($raw) === '') {
                $emit->success(__('No .env file on the server (or it is empty).'));
                $this->completeConsoleAction();

                return;
            }

            ksort($variables);
            foreach ($variables as $key => $value) {
                $value = (string) $value;
                $emit(
                    $value === ''
                        ? sprintf('%s = (empty)', $key)
                        : sprintf('%s = %s (%d chars)', $key, str_repeat('•', min(8, strlen($value))), strlen($value)),
                    'info',
                    'view',
                );
            }

            $emit->success(trans_choice(
                '{1} :count key on the server|[2,*] :count keys on the server',
                count($variables),
                ['count' => count($variables)],
            ));

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'view');
            $this->failConsoleAction($e->getMessage());

            Log::warning('ViewServerEnvJob failed', [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
