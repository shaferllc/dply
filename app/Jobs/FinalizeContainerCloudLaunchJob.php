<?php

namespace App\Jobs;

use App\Actions\Sites\CreateContainerSiteFromInspection;
use App\Models\Organization;
use App\Models\Server;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class FinalizeContainerCloudLaunchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 60;

    /**
     * @param  array<string, mixed>  $inspection
     */
    public function __construct(
        public string $serverId,
        public string $userId,
        public string $organizationId,
        public array $inspection,
        public string $targetFamily,
    ) {}

    public function handle(CreateContainerSiteFromInspection $siteCreator): void
    {
        $server = Server::query()->find($this->serverId);
        $user = User::query()->find($this->userId);
        $organization = Organization::query()->find($this->organizationId);

        if (! $server || ! $user || ! $organization) {
            return;
        }

        if ($server->status === Server::STATUS_ERROR) {
            $this->updateLaunchState($server, 'failed', 'Server provisioning failed', 'The remote server failed before Dply could finish the container launch.', 'error');

            return;
        }

        $setupStatus = (string) ($server->setup_status ?? '');
        $waitingForSetup = in_array($setupStatus, [
            Server::SETUP_STATUS_PENDING,
            Server::SETUP_STATUS_RUNNING,
        ], true);

        if ($server->status !== Server::STATUS_READY || $waitingForSetup) {
            $this->updateLaunchState(
                $server,
                'waiting_for_server',
                'Provisioning server',
                'Waiting for the remote server to finish provisioning before creating the site.',
            );
            $this->release(15);

            return;
        }

        $existingSiteId = data_get($server->meta, 'container_launch.site_id');
        if (is_string($existingSiteId) && $existingSiteId !== '') {
            $this->updateLaunchState(
                $server,
                'waiting_for_site_provisioning',
                'Provisioning site workspace',
                'The site has already been created. Dply is waiting for provisioning and the first deployment workflow to finish.',
            );

            return;
        }

        $this->updateLaunchState(
            $server,
            'creating_site',
            'Creating site record',
            'Remote server is ready. Creating the site from the inspected repository.',
        );

        $site = $siteCreator->handle($server, $user, $organization, $this->inspection, $this->targetFamily);

        $this->updateLaunchState(
            $server->fresh() ?? $server,
            'waiting_for_site_provisioning',
            'Provisioning site workspace',
            'Site created. Provisioning and first deployment have been queued.',
            'info',
            [
                'site_id' => (string) $site->id,
                'site_name' => $site->name,
            ],
        );

        Bus::chain([
            new ProvisionSiteJob($site->id),
            new RunSiteDeploymentJob($site, SiteDeployment::TRIGGER_API, null, $this->userId),
        ])->dispatch();
    }

    public function failed(?Throwable $exception): void
    {
        $server = Server::query()->find($this->serverId);
        if (! $server) {
            return;
        }

        $this->updateLaunchState(
            $server,
            'failed',
            'Container launch failed',
            $exception?->getMessage() ?: 'The container launch did not finish successfully.',
            'error',
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function updateLaunchState(
        Server $server,
        string $status,
        string $stepLabel,
        string $message,
        string $level = 'info',
        array $context = [],
    ): void {
        $meta = is_array($server->meta) ? $server->meta : [];
        $launch = is_array($meta['container_launch'] ?? null) ? $meta['container_launch'] : [];
        $events = is_array($launch['events'] ?? null) ? $launch['events'] : [];
        $lastEvent = end($events);

        $eventPayload = [
            'at' => now()->toIso8601String(),
            'level' => $level,
            'message' => $message,
        ];

        if ($context !== []) {
            $eventPayload['context'] = collect($context)
                ->reject(fn (mixed $value): bool => $value === null || $value === '' || $value === [])
                ->all();
        }

        if (! is_array($lastEvent) || ($lastEvent['message'] ?? null) !== $message || ($lastEvent['level'] ?? null) !== $level) {
            $events[] = $eventPayload;
        }

        $launch = array_merge($launch, [
            'status' => $status,
            'target_family' => $launch['target_family'] ?? $this->targetFamily,
            'current_step_label' => $stepLabel,
            'summary' => $message,
            'updated_at' => now()->toIso8601String(),
            'events' => array_slice($events, -8),
        ]);

        $meta['container_launch'] = $launch;
        $server->forceFill(['meta' => $meta])->save();
    }
}
