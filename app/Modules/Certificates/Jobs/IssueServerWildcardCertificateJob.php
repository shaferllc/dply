<?php

namespace App\Modules\Certificates\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ServerWildcardCertificate;
use App\Models\Site;
use App\Modules\Certificates\Services\WildcardCertificateIssuer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Issues (or renews) the per-(server, zone) wildcard TLS certificate. The row
 * must already exist — created by the dispatcher (SiteProvisioner / backfill /
 * renewal) with its provider + credential resolved. A cache lock keyed on
 * (server, zone) prevents two concurrent site provisions from both shelling out
 * to certbot for the same wildcard.
 *
 * When an operator triggers issuance from the testing-host card we thread a
 * seeded console-action run id (+ the originating site for the banner subject)
 * so certbot progress streams into the page-top banner live instead of only
 * landing in last_output after the fact. System/renewal dispatches omit those
 * and run silently exactly as before.
 */
class IssueServerWildcardCertificateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $serverId,
        public string $zone,
        public ?string $seededConsoleRunId = null,
        public ?string $consoleSiteId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'wildcard-ssl:'.$this->serverId.':'.strtolower($this->zone);
    }

    protected function consoleSubject(): Model
    {
        // Only resolved on the operator-triggered path (consoleSiteId set); the
        // banner lives on the originating site's routing page.
        return Site::query()->findOrFail($this->consoleSiteId);
    }

    protected function consoleKind(): string
    {
        return 'ssl';
    }

    public function handle(WildcardCertificateIssuer $issuer): void
    {
        $zone = strtolower(trim($this->zone));

        // Opt-in live banner: present only when the operator triggered this from
        // the UI (which seeds a queued console-action row for its own site).
        $emit = null;
        if ($this->seededConsoleRunId !== null && $this->consoleSiteId !== null) {
            $this->bindConsoleRunId($this->seededConsoleRunId);
            $emit = $this->beginConsoleAction();
        }

        $wildcard = ServerWildcardCertificate::query()
            ->where('server_id', $this->serverId)
            ->where('zone', $zone)
            ->first();

        if ($wildcard === null) {
            $emit?->error('No wildcard certificate row for *.'.$zone.' on this server.', 'wildcard');
            $this->failConsoleAction('Wildcard row missing.');

            return;
        }

        if (! $wildcard->needsIssuance()) {
            $emit?->success('*.'.$zone.' is already active — nothing to issue.', 'wildcard');
            $this->completeConsoleAction();

            return;
        }

        $lock = Cache::lock('wildcard-ssl:'.$this->serverId.':'.$zone, 600);
        if (! $lock->get()) {
            // Another worker is already issuing this wildcard — let it finish.
            $emit?->step('wildcard', 'Another worker is already issuing this wildcard — letting it finish.');
            $this->completeConsoleAction();

            return;
        }

        try {
            $wildcard->refresh();
            if (! $wildcard->needsIssuance()) {
                $emit?->success('*.'.$zone.' is already active — nothing to issue.', 'wildcard');
                $this->completeConsoleAction();

                return;
            }

            $emit?->step('wildcard', 'Issuing *.'.$zone.' via certbot DNS-01 — this can take a minute …');
            $issuer->issue($wildcard);

            $wildcard->refresh();
            $output = trim((string) $wildcard->last_output);
            if ($emit !== null && $output !== '') {
                $emit->step('wildcard', $output);
            }
            $emit?->success('*.'.$zone.' wildcard issued and installed.', 'wildcard');
            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            // The issuer already wrote the detailed certbot output to last_output
            // on a cert failure — preserve it rather than clobbering with the
            // terse exception message, and surface it in the banner.
            $wildcard->refresh();
            $detail = trim((string) $wildcard->last_output);
            $wildcard->forceFill([
                'status' => ServerWildcardCertificate::STATUS_FAILED,
                'last_output' => $detail !== '' ? $wildcard->last_output : $e->getMessage(),
            ])->save();

            if ($emit !== null) {
                if ($detail !== '' && $detail !== $e->getMessage()) {
                    $emit->step('wildcard', $detail);
                }
                $emit->error($e->getMessage(), 'wildcard');
            }
            $this->failConsoleAction($e->getMessage());

            Log::warning('IssueServerWildcardCertificateJob failed', [
                'server_id' => $this->serverId,
                'zone' => $zone,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
