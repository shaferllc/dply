<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Backends;

use App\Models\CloudDeployTask;
use App\Models\CloudWorker;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Modules\Cloud\Backends\Concerns\BuildsDoAppSpec;
use App\Modules\Cloud\Backends\Concerns\ManagesDoAppDomainsEnv;
use App\Modules\Cloud\Backends\Concerns\ManagesDoAppWorkersScaling;
use App\Modules\Cloud\Backends\Concerns\ProvisionsDoAppPlatform;
use App\Modules\Cloud\Backends\Concerns\ReadsDoAppState;
use App\Modules\Cloud\Services\DigitalOceanAppPlatformService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DigitalOceanAppPlatformBackend implements CloudBackend
{
    use BuildsDoAppSpec;
    use ManagesDoAppDomainsEnv;
    use ManagesDoAppWorkersScaling;
    use ProvisionsDoAppPlatform;
    use ReadsDoAppState;
    use ResolvesMetricWindows;

    public function providerKey(): string
    {
        return 'digitalocean_app_platform';
    }

    public function supportsWorkers(): bool
    {
        // App Platform supports `workers` components — long-running,
        // no HTTP — in the same app spec as the web service.
        return true;
    }

    public function supportsDeployTasks(): bool
    {
        // App Platform supports `jobs` components keyed by PRE_DEPLOY /
        // POST_DEPLOY / FAILED_DEPLOY / MANUAL in the same app spec.
        return true;
    }

    public function supportsAlerts(): bool
    {
        // App Platform has first-class `alerts` in the spec plus a
        // per-alert destinations endpoint (Slack webhook + emails).
        return true;
    }


}
