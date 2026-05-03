<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Edge\EdgeRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Attach a custom hostname to an edge site's deployed app on its
 * backend, persist any DNS validation records returned by the
 * backend onto site->meta so the dashboard can show them to the
 * operator. Idempotent — re-running with the same hostname is a
 * no-op (DO + App Runner both reject duplicates gracefully).
 */
class AttachEdgeDomainJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public string $siteId, public string $hostname) {}

    public function handle(): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }
        $backend = EdgeRouter::backendFor($site);
        $credential = EdgeRouter::credentialFor($site);
        if ($backend === null || $credential === null) {
            return;
        }

        $records = $backend->attachDomain($site, $credential, $this->hostname);

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['container'] = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $domains = is_array($meta['container']['domains'] ?? null) ? $meta['container']['domains'] : [];
        $domains[$this->hostname] = [
            'attached_at' => now()->toIso8601String(),
            'validation_records' => $records,
        ];
        $meta['container']['domains'] = $domains;
        $site->update(['meta' => $meta]);
    }
}
