<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class VerifyEdgeCustomDomainsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(EdgeCustomDomainProvisioner $provisioner): void
    {
        Site::query()
            ->whereNotNull('edge_backend')
            ->chunkById(50, function ($sites) use ($provisioner): void {
                foreach ($sites as $site) {
                    if (! $site->usesEdgeRuntime()) {
                        continue;
                    }

                    $routing = is_array($site->edgeMeta()['routing'] ?? null) ? $site->edgeMeta()['routing'] : [];
                    $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];

                    foreach ($domains as $hostname => $info) {
                        if (! is_string($hostname) || $hostname === '') {
                            continue;
                        }
                        if (! is_array($info) || ($info['dns_status'] ?? null) !== 'pending') {
                            continue;
                        }

                        $provisioner->verify($site->fresh(), $hostname);
                    }
                }
            });
    }
}
