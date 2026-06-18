<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Jobs\TeardownEdgeSiteJob;
use App\Models\Site;

class EdgeSiteCanceller
{
    public function cancel(Site $site): void
    {
        if (! $site->usesEdgeRuntime()) {
            throw new \InvalidArgumentException('Site is not an Edge site.');
        }

        TeardownEdgeSiteJob::dispatchSync($site->id);
    }
}
