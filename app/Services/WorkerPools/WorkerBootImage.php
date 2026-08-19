<?php

declare(strict_types=1);

namespace App\Services\WorkerPools;

use App\Enums\ServerProvider;
use App\Jobs\CreateServerImageJob;
use App\Models\Server;
use App\Models\ServerImage;
use App\Support\Servers\FakeCloudProvision;
use App\Support\Servers\ServerImageProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Org-scoped golden image of a worker after setup (packages in, site not
 * copied). Later workers in the same region boot from it so apt skip-fasts.
 */
class WorkerBootImage
{
    public function __construct(
        private ServerImageProvider $images,
    ) {}

    public function providerImageIdFor(Server $server): ?string
    {
        return $this->readyImage($server)?->provider_image_id;
    }

    public function readyImage(Server $server): ?ServerImage
    {
        $query = $this->lookup($server, [ServerImage::STATUS_COMPLETED]);
        if ($query === null) {
            return null;
        }

        $image = $query->whereNotNull('provider_image_id')->first();

        return $image instanceof ServerImage ? $image : null;
    }

    /**
     * @return array{state: string, message: string}|null
     */
    public function noteFor(Server $server): ?array
    {
        if ($this->readyImage($server) instanceof ServerImage) {
            return [
                'state' => 'ready',
                'message' => __('Later workers in this region boot from a saved image of the stack — setup skips the long package install.'),
            ];
        }

        if ($this->inFlight($server) instanceof ServerImage) {
            return [
                'state' => 'creating',
                'message' => __('Saving an image of the installed stack so the next worker is faster.'),
            ];
        }

        return null;
    }

    /**
     * Freeze the disk now (provider snapshot POST), then poll in the background.
     * Must run before site files are copied onto the worker.
     */
    public function captureBeforeReplay(Server $worker): ?ServerImage
    {
        if (! $this->shouldBake($worker)) {
            return $this->readyImage($worker) ?? $this->inFlight($worker);
        }

        if ($existing = $this->readyImage($worker) ?? $this->inFlight($worker)) {
            return $existing;
        }

        $name = 'dply-worker-'.str($worker->region ?: 'image')->slug()->value().'-'.now()->format('Ymd-His');

        $image = ServerImage::query()->create([
            'server_id' => $worker->id,
            'organization_id' => $worker->organization_id,
            'user_id' => $worker->user_id,
            'provider' => $worker->provider->value,
            'name' => $name,
            'purpose' => ServerImage::PURPOSE_WORKER_BAKE,
            'status' => ServerImage::STATUS_PENDING,
            'region' => $worker->region,
        ]);

        try {
            $started = $this->images->start($worker, $name);
            $image->update([
                'status' => ServerImage::STATUS_CREATING,
                'provider_image_id' => $started['provider_image_id'] !== '' ? $started['provider_image_id'] : null,
                'provider_action_id' => $started['provider_action_id'],
                'region' => $started['region'] ?: $worker->region,
            ]);
        } catch (\Throwable $e) {
            $image->update([
                'status' => ServerImage::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            Log::warning('worker-boot-image: start failed', [
                'server_id' => $worker->id,
                'error' => $e->getMessage(),
            ]);

            return $image;
        }

        CreateServerImageJob::dispatch($image->id);

        return $image->fresh() ?? $image;
    }

    private function shouldBake(Server $worker): bool
    {
        if (! $worker->isWorkerServer() || ! $worker->isProvisioningComplete()) {
            return false;
        }

        if (FakeCloudProvision::isFakeServer($worker)) {
            return false;
        }

        if (! ServerImageProvider::supports($worker) || blank($worker->provider_id) || blank($worker->organization_id)) {
            return false;
        }

        // Already booted from a bake — don't snapshot a copy of a copy.
        return blank(data_get($worker->meta, 'boot_image_id'));
    }

    private function inFlight(Server $server): ?ServerImage
    {
        return $this->lookup($server, [ServerImage::STATUS_PENDING, ServerImage::STATUS_CREATING])?->first();
    }

    /**
     * @param  list<string>  $statuses
     */
    private function lookup(Server $server, array $statuses): ?Builder
    {
        if (! filled($server->organization_id) || ! $server->provider instanceof ServerProvider) {
            return null;
        }

        $query = ServerImage::query()
            ->where('organization_id', $server->organization_id)
            ->where('provider', $server->provider->value)
            ->where('purpose', ServerImage::PURPOSE_WORKER_BAKE)
            ->whereIn('status', $statuses)
            ->latest();

        // DigitalOcean snapshots are region-scoped; Hetzner/Linode/Vultr are not.
        if ($server->provider === ServerProvider::DigitalOcean) {
            $region = trim((string) $server->region);
            if ($region === '') {
                return null;
            }
            $query->where('region', $region);
        }

        return $query;
    }
}
