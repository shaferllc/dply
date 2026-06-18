<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Modules\Cloud\Backends\CloudRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DetachCloudDomainJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public string $siteId, public string $hostname) {}

    public function handle(): void
    {
        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }
        $backend = CloudRouter::backendFor($site);
        $credential = CloudRouter::credentialFor($site);
        if ($backend === null || $credential === null) {
            return;
        }

        $backend->detachDomain($site, $credential, $this->hostname);

        $meta = $site->meta;
        if (isset($meta['container']['domains'][$this->hostname])) {
            unset($meta['container']['domains'][$this->hostname]);
            $site->update(['meta' => $meta]);
        }
    }
}
