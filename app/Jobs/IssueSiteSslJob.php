<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\Site;
use App\Services\Sites\SiteSslProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IssueSiteSslJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
        public ?string $email = null,
        public ?string $userId = null,
    ) {}

    /** Auto-expire the unique lock so a lost/killed run can't wedge it forever. */
    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return 'console-action:ssl:'.$this->siteId;
    }

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'ssl';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    public function handle(SiteSslProvisioner $provisioner): void
    {
        $site = Site::query()->find($this->siteId);
        if (! $site) {
            return;
        }

        $emit = $this->beginConsoleAction();

        try {
            // SSL provisioner today returns a transcript string and doesn't
            // accept a streaming emitter. Wire the final transcript into the
            // console row line-by-line so the operator still sees structured
            // output instead of one giant blob.
            $output = $provisioner->provision($site, $this->email);
            foreach (preg_split('/\r\n|\r|\n/', trim((string) $output)) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $emit($line, ConsoleAction::LEVEL_INFO, 'ssl');
            }
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error($e->getMessage(), 'ssl');
            $this->failConsoleAction($e->getMessage());

            Log::warning('IssueSiteSslJob failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
