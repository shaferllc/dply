<?php

declare(strict_types=1);

namespace App\Livewire\Serverless;

use App\Jobs\ProvisionServerlessDatabaseJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Provision + show a DigitalOcean Managed Database for a serverless function.
 *
 * Embedded in the serverless workspace dashboard. Kicking off a provision
 * dispatches {@see ProvisionServerlessDatabaseJob}, which creates the cluster
 * and — once online — writes the connection into the function's managed
 * environment. The panel polls while the cluster is coming up.
 */
class DatabasePanel extends Component
{
    use DispatchesToastNotifications;

    public string $siteId = '';

    public string $engine = 'pg';

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

        $this->validate([
            'engine' => ['required', 'in:pg,mysql'],
            'size' => ['required', 'string', 'max:64'],
        ]);

        $meta = is_array($site->meta) ? $site->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $current = is_array($serverless['database'] ?? null) ? $serverless['database'] : [];

        if (in_array($current['status'] ?? '', ['provisioning', 'online'], true)) {
            $this->toastError(__('This function already has a database.'));

            return;
        }

        // Retry after an error keeps any cluster already created, so the job
        // re-polls it instead of leaving an orphan and creating another.
        if (! empty($current['cluster_id'])) {
            $current['status'] = 'provisioning';
            unset($current['error']);
            $serverless['database'] = $current;
        } else {
            $serverless['database'] = [
                'engine' => $this->engine,
                'size' => $this->size,
                'status' => 'provisioning',
            ];
        }
        $meta['serverless'] = $serverless;
        $site->forceFill(['meta' => $meta])->save();

        ProvisionServerlessDatabaseJob::dispatch($site->id);
        $this->toastSuccess(__('Provisioning a database — this takes a few minutes. Redeploy once it is online.'));
    }

    public function render(): View
    {
        $site = $this->site();
        $serverless = is_array($site->meta['serverless'] ?? null) ? $site->meta['serverless'] : [];
        $database = is_array($serverless['database'] ?? null) ? $serverless['database'] : [];

        return view('livewire.serverless.database-panel', [
            'database' => $database,
            'state' => (string) ($database['status'] ?? ''),
            'estimate' => app(\App\Services\Serverless\ServerlessCostEstimator::class)->databaseMonthly($this->size),
        ]);
    }
}
