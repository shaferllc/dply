<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Triggers a redeploy of the existing container app on its
 * backend. If a new image tag is supplied, updateImage() is
 * called first so the next deploy picks it up.
 */
class RedeployCloudSiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public string $siteId, public ?string $newImage = null) {}

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

        $previousImage = $site->container_image;
        if (is_string($this->newImage) && $this->newImage !== '' && $this->newImage !== $previousImage) {
            $site->update(['container_image' => $this->newImage]);
            $backend->updateImage($site->fresh(), $credential, $this->newImage);
        }

        $result = $backend->redeploy($site->fresh(), $credential);

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['container'] = array_merge($meta['container'] ?? [], [
            'last_deployment_id' => $result['deployment_id'],
            'last_deploy_started_at' => now()->toIso8601String(),
        ]);

        // Append to image history so the operator can roll back to
        // any previously-deployed tag. Cap at the most recent 10
        // entries — beyond that the dashboard list gets unwieldy.
        $history = is_array($meta['container']['image_history'] ?? null) ? $meta['container']['image_history'] : [];
        $deployedImage = $this->newImage !== '' ? $this->newImage : $previousImage;
        if ($deployedImage !== '') {
            $history[] = [
                'image' => $deployedImage,
                'deployed_at' => now()->toIso8601String(),
                'deployment_id' => $result['deployment_id'],
            ];
            $meta['container']['image_history'] = array_slice($history, -10);
        }

        $site->update(['meta' => $meta, 'last_deploy_at' => now()]);
    }
}
