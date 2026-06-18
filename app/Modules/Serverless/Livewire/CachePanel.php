<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire;

use App\Modules\Serverless\Jobs\ProvisionServerlessCacheJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessCostEstimator;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Provision + show a DigitalOcean Managed Redis cache for a serverless
 * function. The counterpart to {@see DatabasePanel} — once online,
 * {@see ProvisionServerlessCacheJob} wires REDIS_* + CACHE_STORE=redis into
 * the function's managed environment.
 */
class CachePanel extends Component
{
    use DispatchesToastNotifications;

    public string $siteId = '';

    public string $size = 'db-s-1vcpu-1gb';

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->siteId = $site->id;
    }

    private function site(): Site
    {
        return Site::findOrFail($this->siteId);
    }

    public function provision(): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $this->validate(['size' => ['required', 'string', 'max:64']]);

        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $current = is_array($serverless['cache'] ?? null) ? $serverless['cache'] : [];

        if (in_array($current['status'] ?? '', ['provisioning', 'online'], true)) {
            $this->toastError(__('This function already has a cache.'));

            return;
        }

        if (! empty($current['cluster_id'])) {
            $current['status'] = 'provisioning';
            unset($current['error']);
            $serverless['cache'] = $current;
        } else {
            $serverless['cache'] = ['size' => $this->size, 'status' => 'provisioning'];
        }
        $meta['serverless'] = $serverless;
        $site->forceFill(['meta' => $meta])->save();

        ProvisionServerlessCacheJob::dispatch($site->id);
        $this->toastSuccess(__('Provisioning Redis — this takes a few minutes. Redeploy once it is online.'));
    }

    public function render(): View
    {
        $site = $this->site();
        $serverless = is_array($site->meta['serverless'] ?? null) ? $site->meta['serverless'] : [];
        $cache = is_array($serverless['cache'] ?? null) ? $serverless['cache'] : [];

        return view('livewire.serverless.cache-panel', [
            'cache' => $cache,
            'state' => (string) ($cache['status'] ?? ''),
            'estimate' => app(ServerlessCostEstimator::class)->cacheMonthly($this->size),
        ]);
    }
}
