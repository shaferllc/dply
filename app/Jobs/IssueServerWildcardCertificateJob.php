<?php

namespace App\Jobs;

use App\Models\ServerWildcardCertificate;
use App\Services\Certificates\WildcardCertificateIssuer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
 */
class IssueServerWildcardCertificateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $serverId,
        public string $zone,
    ) {}

    public function uniqueId(): string
    {
        return 'wildcard-ssl:'.$this->serverId.':'.strtolower($this->zone);
    }

    public function handle(WildcardCertificateIssuer $issuer): void
    {
        $zone = strtolower(trim($this->zone));

        $wildcard = ServerWildcardCertificate::query()
            ->where('server_id', $this->serverId)
            ->where('zone', $zone)
            ->first();

        if ($wildcard === null) {
            return;
        }

        if (! $wildcard->needsIssuance()) {
            return;
        }

        $lock = Cache::lock('wildcard-ssl:'.$this->serverId.':'.$zone, 600);
        if (! $lock->get()) {
            // Another worker is already issuing this wildcard — let it finish.
            return;
        }

        try {
            $wildcard->refresh();
            if (! $wildcard->needsIssuance()) {
                return;
            }

            $issuer->issue($wildcard);
        } catch (\Throwable $e) {
            // Never leave the row stuck in 'issuing' — mark it failed so the
            // next provision probe / renewal run re-dispatches issuance.
            $wildcard->forceFill([
                'status' => ServerWildcardCertificate::STATUS_FAILED,
                'last_output' => $e->getMessage(),
            ])->save();

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
