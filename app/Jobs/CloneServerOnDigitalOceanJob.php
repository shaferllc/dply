<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\DigitalOceanService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates the DigitalOcean snapshot → new-droplet flow for {@see CloneServerOnDigitalOcean}.
 *
 * Steps:
 *   1. Snapshot the source droplet (returns an action ID).
 *   2. Poll the action until completed (snapshots take ~3–8 min on small droplets).
 *   3. Locate the resulting snapshot in /v2/snapshots by name.
 *   4. Create a new droplet from that snapshot image — fresh SSH key generated,
 *      so the clone's audit trail is distinct from the source's.
 *   5. Update the cloned Server row with the new provider_id + SSH material.
 *   6. Hand off to {@see PollDropletIpJob} which polls for the public IP and
 *      transitions the row to READY.
 *
 * Progress streams into a `clone_server` ConsoleAction so operators can watch
 * the long-running flow in the banner the same way they watch installs and
 * webserver switches. Failure at any step transitions the cloned Server row
 * to ERROR and marks the ConsoleAction failed.
 */
class CloneServerOnDigitalOceanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 2400;

    public int $tries = 1;

    public function __construct(
        public string $sourceServerId,
        public string $cloneServerId,
        public string $snapshotName,
    ) {}

    public function handle(): void
    {
        $source = Server::find($this->sourceServerId);
        $clone = Server::find($this->cloneServerId);
        if ($source === null || $clone === null) {
            $this->failClone($clone, 'Source or clone server row vanished mid-flight.');

            return;
        }

        $credential = $source->providerCredential;
        if (! $credential || $credential->provider !== 'digitalocean') {
            $this->failClone($clone, 'Source server has no DigitalOcean credential.');

            return;
        }

        $consoleRow = $this->latestCloneConsoleAction($clone);
        $emitter = $consoleRow !== null ? new ConsoleEmitter((string) $consoleRow->id) : null;
        if ($consoleRow !== null) {
            DB::table('console_actions')->where('id', $consoleRow->id)->update([
                'status' => ConsoleAction::STATUS_RUNNING,
                'started_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $do = new DigitalOceanService($credential);

        try {
            $emitter?->step('dply', __('Triggering snapshot of source droplet :id …', ['id' => $source->provider_id]));
            $action = $do->snapshotDroplet((int) $source->provider_id, $this->snapshotName);
            $actionId = (int) ($action['id'] ?? 0);
            if ($actionId === 0) {
                throw new \RuntimeException('DigitalOcean did not return a snapshot action id.');
            }
            if ($emitter !== null) {
                $emitter('dply: snapshot action queued (id='.$actionId.')');
            }
        } catch (\Throwable $e) {
            $this->failClone($clone, 'Snapshot trigger failed: '.$e->getMessage(), $consoleRow, $emitter);

            return;
        }

        try {
            $emitter?->step('dply', __('Waiting for snapshot to complete (this can take 3–8 minutes) …'));
            $do->waitForDropletAction(
                dropletId: (int) $source->provider_id,
                actionId: $actionId,
                timeoutSeconds: 1800,
                pollSeconds: 15,
                onTick: function (array $action) use ($emitter): void {
                    $status = (string) ($action['status'] ?? 'in-progress');
                    if ($emitter !== null && $status !== 'completed') {
                        $emitter('dply: snapshot status='.$status);
                    }
                },
            );
            $emitter?->success(__('Snapshot complete.'), 'dply');
        } catch (\Throwable $e) {
            $this->failClone($clone, 'Snapshot wait failed: '.$e->getMessage(), $consoleRow, $emitter);

            return;
        }

        $snapshotId = null;
        try {
            $emitter?->step('dply', __('Locating snapshot image …'));
            $snapshotId = $this->findSnapshotIdByName($do, $this->snapshotName, (string) $source->provider_id);
            if ($snapshotId === null) {
                throw new \RuntimeException('Could not find snapshot named "'.$this->snapshotName.'" in /snapshots.');
            }
            if ($emitter !== null) {
                $emitter('dply: snapshot image id='.$snapshotId);
            }
        } catch (\Throwable $e) {
            $this->failClone($clone, 'Snapshot lookup failed: '.$e->getMessage(), $consoleRow, $emitter);

            return;
        }

        try {
            $emitter?->step('dply', __('Generating fresh SSH key for the clone …'));
            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();
            $doKeyName = 'dply-'.$clone->name.'-'.Str::random(6);
            $doKey = $do->addSshKey($doKeyName, $keys['recovery_public_key']);
            $sshKeyId = $doKey['id'] ?? $doKey['fingerprint'] ?? null;
            if ($sshKeyId === null) {
                throw new \RuntimeException('DigitalOcean did not return an SSH key id for the clone.');
            }
        } catch (\Throwable $e) {
            $this->failClone($clone, 'SSH key registration failed: '.$e->getMessage(), $consoleRow, $emitter);

            return;
        }

        try {
            $emitter?->step('dply', __('Creating cloned droplet in :region (:size) …', [
                'region' => $clone->region,
                'size' => $clone->size,
            ]));

            $sourceMeta = is_array($source->meta) ? $source->meta : [];
            $doOpts = is_array($sourceMeta['digitalocean'] ?? null) ? $sourceMeta['digitalocean'] : [];

            $droplet = $do->createDroplet(
                name: $clone->name,
                region: (string) $clone->region,
                size: (string) $clone->size,
                image: $snapshotId,
                sshKeyIds: [$sshKeyId],
                options: [
                    'ipv6' => (bool) ($doOpts['ipv6'] ?? false),
                    'backups' => (bool) ($doOpts['backups'] ?? false),
                    'monitoring' => (bool) ($doOpts['monitoring'] ?? false),
                    'vpc_uuid' => isset($doOpts['vpc_uuid']) && is_string($doOpts['vpc_uuid']) && $doOpts['vpc_uuid'] !== ''
                        ? $doOpts['vpc_uuid']
                        : null,
                    'tags' => isset($doOpts['tags']) && is_array($doOpts['tags']) ? $doOpts['tags'] : [],
                    'user_data' => '',
                ],
            );
        } catch (\Throwable $e) {
            $this->failClone($clone, 'Droplet create failed: '.$e->getMessage(), $consoleRow, $emitter);

            return;
        }

        $newDropletId = (string) ($droplet['id'] ?? '');
        if ($newDropletId === '') {
            $this->failClone($clone, 'DigitalOcean did not return a droplet id for the clone.', $consoleRow, $emitter);

            return;
        }

        $clone->update([
            'provider_id' => $newDropletId,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => (string) config('services.digitalocean.ssh_user', 'root'),
        ]);

        $emitter?->success(__('Droplet :id created. Polling for IP …', ['id' => $newDropletId]), 'dply');

        $clone->loadMissing('organization');
        if ($clone->organization) {
            audit_log($clone->organization, null, 'server.clone.droplet_created', $clone, null, [
                'source_server_id' => (string) $this->sourceServerId,
                'clone_server_id' => (string) $this->cloneServerId,
                'snapshot_name' => $this->snapshotName,
                'droplet_id' => $newDropletId,
            ]);
        }

        if ($consoleRow !== null) {
            // Hand off to the IP poll job; PollDropletIpJob flips status to
            // READY and pings ServerProvisionDispatch (which is a no-op for
            // clones because we deliberately did NOT copy server_role into
            // the clone's meta — see CloneServerOnDigitalOcean::cloneableMeta).
            DB::table('console_actions')->where('id', $consoleRow->id)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        }

        PollDropletIpJob::dispatch($clone->fresh())->delay(now()->addSeconds(15));
    }

    protected function findSnapshotIdByName(DigitalOceanService $do, string $name, string $sourceDropletId): ?string
    {
        $snapshots = $do->getSnapshots('droplet');
        foreach ($snapshots as $snapshot) {
            if ((string) ($snapshot['name'] ?? '') === $name) {
                return (string) ($snapshot['id'] ?? '');
            }
        }

        // Fallback: most recent snapshot whose resource_id matches the source.
        // Catches the (rare) case where DO mangles the name with a suffix.
        $candidate = null;
        $candidateAt = null;
        foreach ($snapshots as $snapshot) {
            $resourceIds = $snapshot['resource_id'] ?? null;
            if (is_array($resourceIds)) {
                $matches = in_array($sourceDropletId, array_map('strval', $resourceIds), true);
            } else {
                $matches = (string) $resourceIds === $sourceDropletId;
            }
            if (! $matches) {
                continue;
            }
            $createdAt = isset($snapshot['created_at']) && is_string($snapshot['created_at'])
                ? Carbon::parse($snapshot['created_at'])->getTimestamp()
                : 0;
            if ($candidateAt === null || $createdAt > $candidateAt) {
                $candidate = (string) ($snapshot['id'] ?? '');
                $candidateAt = $createdAt;
            }
        }

        return $candidate !== '' ? $candidate : null;
    }

    protected function latestCloneConsoleAction(Server $clone): ?ConsoleAction
    {
        return ConsoleAction::query()
            ->where('subject_type', $clone->getMorphClass())
            ->where('subject_id', $clone->id)
            ->where('kind', 'clone_server')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();
    }

    protected function failClone(?Server $clone, string $message, ?ConsoleAction $consoleRow = null, ?ConsoleEmitter $emitter = null): void
    {
        if ($clone !== null) {
            $clone->update(['status' => Server::STATUS_ERROR]);
            $clone->loadMissing('organization');
            if ($clone->organization) {
                audit_log($clone->organization, null, 'server.clone.failed', $clone, null, [
                    'source_server_id' => (string) $this->sourceServerId,
                    'clone_server_id' => (string) $this->cloneServerId,
                    'snapshot_name' => $this->snapshotName,
                    'error' => mb_substr($message, 0, 1000),
                ]);
            }
        }
        if ($consoleRow !== null) {
            try {
                $emitter?->error($message, 'dply');
                DB::table('console_actions')->where('id', $consoleRow->id)->update([
                    'status' => ConsoleAction::STATUS_FAILED,
                    'finished_at' => now(),
                    'error' => mb_substr($message, 0, 2000),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable) {
                // Best-effort.
            }
        }
    }
}
