<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Serverless\Services\ServerlessAppBucketProvisioner;
use App\Modules\Serverless\Services\ServerlessAssetDomainProvisioner;
use App\Modules\Serverless\Services\ServerlessAssetGuardrail;
use App\Modules\Serverless\Services\ServerlessAssetGuardrailStatus;
use App\Modules\Serverless\Support\ServerlessAssetHost;
use App\Support\Sites\SiteRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * Where this function's front-end build is served from, what it is using
 * against its included allowance, and the custom domain controls.
 *
 * Reads the guardrail rather than recomputing totals, so the number shown here
 * and the state the nightly evaluator notifies on can never disagree.
 *
 * The copy is deliberate about consequences: going over the allowance bills the
 * overage, it does not stop delivery. Storage is the one that can actually
 * block, and only at deploy time, before anything uploads.
 */
class AssetsPanel extends Component
{
    use DispatchesToastNotifications;

    public string $siteId = '';

    public string $newHostname = '';

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->siteId = $site->id;
    }

    private function site(): Site
    {
        // Through the registry: the sibling serverless panels on this page each
        // resolved the same row, so one render issued the sites SELECT per panel.
        return app(SiteRegistry::class)->findOrFail($this->siteId);
    }

    public function attachDomain(): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $this->validate([
            'newHostname' => ['required', 'string', 'max:255'],
        ]);

        try {
            app(ServerlessAssetDomainProvisioner::class)->attach($site, $this->newHostname);
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->newHostname = '';
        $this->toastSuccess(__('Domain attached — point it at this function\'s asset hostname, then verify.'));
    }

    public function verifyDomain(string $hostname): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $entry = app(ServerlessAssetDomainProvisioner::class)->verify($site, $hostname);

        if (($entry['status'] ?? null) === ServerlessAssetDomainProvisioner::STATUS_ACTIVE) {
            $this->toastSuccess(__('Domain is active. It takes effect on the next deploy.'));

            return;
        }

        $this->toastError(__('Still validating. Certificate issuance can take a few minutes.'));
    }

    public function detachDomain(string $hostname): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        app(ServerlessAssetDomainProvisioner::class)->detach($site, $hostname);
        $this->toastSuccess(__('Domain removed.'));
    }

    /**
     * The function's own upload bucket, if it has one — separate from the
     * published build above, and the only storage the app itself can write.
     *
     * Null until the function has deployed once with app buckets enabled, in
     * which case the panel says nothing about storage at all rather than
     * advertising a feature this environment does not provision.
     *
     * @return array{bucket: string, disk: string, env_prefix: string, region: string, bytes: int, measured_at: string}|null
     */
    private function appBucket(Site $site): ?array
    {
        $binding = app(ServerlessAppBucketProvisioner::class)->existingBinding($site);
        if (! $binding instanceof SiteBinding) {
            return null;
        }

        $bucket = trim((string) (data_get($binding->config, 'bucket') ?? ''));
        if ($bucket === '') {
            return null;
        }

        $disk = trim((string) (data_get($binding->config, 'disk') ?? 'uploads'));
        $meta = $site->serverlessConfig()['app_bucket'] ?? [];
        $meta = is_array($meta) ? $meta : [];

        return [
            'bucket' => $bucket,
            'disk' => $disk,
            'env_prefix' => 'AWS_'.strtoupper($disk).'_',
            'region' => trim((string) (data_get($binding->config, 'region') ?? '')),
            'bytes' => max(0, (int) ($meta['storage_bytes'] ?? 0)),
            'measured_at' => trim((string) ($meta['storage_measured_at'] ?? '')),
        ];
    }

    public function render(): View
    {
        $site = $this->site();
        $status = app(ServerlessAssetGuardrail::class)->evaluate($site);
        $serverless = $site->serverlessConfig();
        $assets = is_array($serverless['assets'] ?? null) ? $serverless['assets'] : [];

        return view('livewire.serverless.assets-panel', [
            'status' => $status,
            'state' => $status->state,
            'assetUrl' => trim((string) ($serverless['asset_url'] ?? '')),
            'defaultHostname' => ServerlessAssetHost::hostname($site),
            'cdnEnabled' => (bool) config('serverless.assets.cdn.enabled', false),
            'domains' => app(ServerlessAssetDomainProvisioner::class)->entries($site),
            'publishedAt' => trim((string) ($serverless['assets_published_at'] ?? '')),
            'fileCount' => (int) ($serverless['assets_file_count'] ?? 0),
            'measuredAt' => trim((string) ($assets['storage_measured_at'] ?? '')),
            'appBucket' => $this->appBucket($site),
            'isOver' => $status->state === ServerlessAssetGuardrailStatus::STATE_OVER,
            'isWarn' => $status->state === ServerlessAssetGuardrailStatus::STATE_WARN,
        ]);
    }
}
