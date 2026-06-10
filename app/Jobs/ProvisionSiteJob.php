<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    /**
     * Reachability is retried until a hostname answers or this many probes have
     * run. The window has to outlast managed-DNS publish lag: a freshly-created
     * record can take several minutes to reach a zone's authoritative
     * nameservers (Hetzner in particular publishes new on-dply.* records on a
     * multi-minute delay), so a too-short window leaves a correctly-provisioned
     * testing site stuck at "waiting_for_http" forever.
     */
    private const MAX_PROBE_ATTEMPTS = 24;

    public function __construct(
        public string $siteId,
        public int $probeAttempt = 0,
    ) {}

    public function handle(SiteProvisioner $siteProvisioner): void
    {
        $site = Site::query()->with(['server', 'domains'])->find($this->siteId);
        if (! $site) {
            return;
        }

        if ($site->provisioningState() === 'ready') {
            return;
        }

        try {
            $siteProvisioner->appendLog($site, 'info', 'queued', 'Provisioning job picked up by worker.', [
                'probe_attempt' => $this->probeAttempt,
            ]);

            if ($this->probeAttempt === 0) {
                $siteProvisioner->begin($site);
                $site->refresh();

                // Headless sites (webserver=none) finish inside begin() —
                // no vhost, no testing hostname, nothing to probe. Bail
                // before checkReadiness schedules an HTTP reachability loop
                // against a hostname that nobody ever provisioned.
                if ($site->provisioningState() === 'ready') {
                    return;
                }
            }

            // The vhost is held back until the per-server wildcard TLS cert for
            // the testing zone is installed, so the site's :443 block is present
            // from the first response and it is never published HTTP-only. Keep
            // retrying within the probe window until issuance completes.
            if ($site->provisioningState() === 'waiting_for_wildcard_tls') {
                if (! $siteProvisioner->ensureWebserverConfigForReachability($site)) {
                    if ($this->probeAttempt >= self::MAX_PROBE_ATTEMPTS) {
                        $siteProvisioner->markTimedOut($site, 'The testing hostname was assigned, but the wildcard TLS certificate for its zone could not be issued before the retry limit was reached. dply requires SSL before publishing the site — check the wildcard certificate output and retry.');

                        return;
                    }

                    $delaySeconds = $this->reachabilityDelaySeconds($this->probeAttempt);
                    $siteProvisioner->appendLog($site, 'info', 'waiting_for_wildcard_tls', 'Waiting for the wildcard TLS certificate before writing the web server config.', [
                        'next_probe_attempt' => $this->probeAttempt + 1,
                        'delay_seconds' => $delaySeconds,
                    ]);

                    static::dispatch($site->id, $this->probeAttempt + 1)
                        ->delay(now()->addSeconds($delaySeconds));

                    return;
                }

                $site->refresh();
            }

            $result = $siteProvisioner->checkReadiness($site);
            if ($result['ok']) {
                return;
            }

            if ($this->probeAttempt >= self::MAX_PROBE_ATTEMPTS) {
                $siteProvisioner->markTimedOut($site, 'The site configuration was written, but no testing or primary domain responded before the retry limit was reached. This usually means DNS for the hostname has not propagated to its authoritative nameservers yet — retry once it resolves.');

                return;
            }

            $delaySeconds = $this->reachabilityDelaySeconds($this->probeAttempt);
            $siteProvisioner->appendLog($site, 'info', 'waiting_for_http', 'Scheduling another reachability check.', [
                'next_probe_attempt' => $this->probeAttempt + 1,
                'delay_seconds' => $delaySeconds,
            ]);

            static::dispatch($site->id, $this->probeAttempt + 1)
                ->delay(now()->addSeconds($delaySeconds));
        } catch (\Throwable $e) {
            $siteProvisioner->markFailed($site, $e);
            Log::warning('ProvisionSiteJob failed', [
                'site_id' => $site->id,
                'probe_attempt' => $this->probeAttempt,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Backoff for reachability probes: snappy at first so a fast-publishing zone
     * (DigitalOcean, Cloudflare) finishes in seconds, then widening so the full
     * window spans ~15 minutes to ride out slow authoritative-NS publishes
     * without flooding the queue. 6×15s + 9×30s + 9×60s ≈ 900s.
     */
    private function reachabilityDelaySeconds(int $attempt): int
    {
        return match (true) {
            $attempt < 6 => 15,
            $attempt < 15 => 30,
            default => 60,
        };
    }
}
