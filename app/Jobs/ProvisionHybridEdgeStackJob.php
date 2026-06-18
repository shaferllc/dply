<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Edge\CreateEdgeSite;
use App\Models\Site;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Waits for a hybrid-stack Cloud origin to expose a live URL, then
 * creates the linked hybrid Edge site from stored stack metadata.
 */
class ProvisionHybridEdgeStackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    private const MAX_POLL_ATTEMPTS = 20;

    public function __construct(public string $cloudSiteId) {}

    public function handle(): void
    {
        $cloudSite = Site::find($this->cloudSiteId);
        if ($cloudSite === null || ! $cloudSite->usesContainerRuntime()) {
            return;
        }

        $meta = $cloudSite->meta;
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $stack = is_array($container['hybrid_edge_stack'] ?? null) ? $container['hybrid_edge_stack'] : [];

        if ($stack === []) {
            return;
        }

        $status = (string) ($stack['status'] ?? '');
        if (in_array($status, ['complete', 'failed'], true)) {
            return;
        }

        if (is_string($stack['edge_site_id'] ?? null) && $stack['edge_site_id'] !== '') {
            $this->updateStackMeta($cloudSite, ['status' => 'complete']);

            return;
        }

        (new PollCloudStatusJob($cloudSite->id))->handle();
        $cloudSite->refresh();

        if ($cloudSite->status === Site::STATUS_CONTAINER_FAILED) {
            $this->markFailed($cloudSite, 'Cloud origin provisioning failed.');

            return;
        }

        $liveUrl = $cloudSite->containerLiveUrl();
        if ($liveUrl === null) {
            $attempts = (int) ($stack['poll_attempts'] ?? 0) + 1;
            if ($attempts >= self::MAX_POLL_ATTEMPTS) {
                $this->markFailed($cloudSite, 'Timed out waiting for the Cloud origin URL.');

                return;
            }

            $this->updateStackMeta($cloudSite, [
                'status' => 'awaiting_origin',
                'poll_attempts' => $attempts,
            ]);
            $this->release(30);

            return;
        }

        $this->updateStackMeta($cloudSite, ['status' => 'edge_provisioning']);

        $edgePayload = is_array($stack['edge_payload'] ?? null) ? $stack['edge_payload'] : [];
        if ($edgePayload === []) {
            $this->markFailed($cloudSite, 'Hybrid stack metadata is missing Edge deploy settings.');

            return;
        }

        $user = User::find($cloudSite->user_id);
        $organization = $cloudSite->organization;
        if ($user === null || $organization === null) {
            $this->markFailed($cloudSite, 'Could not resolve stack owner for Edge site creation.');

            return;
        }

        try {
            $edgeSite = (new CreateEdgeSite)->handle($user, $organization, array_merge($edgePayload, [
                'runtime_mode' => 'hybrid',
                'origin_url' => $liveUrl,
                'cloud_site_id' => $cloudSite->id,
                'origin_managed' => true,
            ]));
        } catch (Throwable $e) {
            $this->markFailed($cloudSite, $e->getMessage());

            return;
        }

        $this->updateStackMeta($cloudSite, [
            'status' => 'complete',
            'edge_site_id' => (string) $edgeSite->id,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    private function markFailed(Site $cloudSite, string $message): void
    {
        $this->updateStackMeta($cloudSite, [
            'status' => 'failed',
            'error' => $message,
            'failed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function updateStackMeta(Site $cloudSite, array $changes): void
    {
        $meta = $cloudSite->meta;
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $stack = is_array($container['hybrid_edge_stack'] ?? null) ? $container['hybrid_edge_stack'] : [];
        $container['hybrid_edge_stack'] = array_merge($stack, $changes);
        $meta['container'] = $container;
        $cloudSite->update(['meta' => $meta]);
    }
}
