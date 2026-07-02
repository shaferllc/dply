<?php

namespace App\Jobs;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Modules\Cloud\Services\AwsEc2Service;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Modules\Cloud\Services\HetznerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Re-read a server's hardware facts from its cloud provider and reconcile the
 * stored copy after an operator resizes the machine at the provider.
 *
 * `servers.size` is written once at create/import and never revisited, so a
 * provider-side resize silently strands it (the workspace hero, cost card plan
 * label, and clone flow all read it). Billing is NOT affected — the tier is
 * classified from live agent metric snapshots — but the stored facts should
 * agree with reality. This job fetches the machine by provider_id, updates
 * size (+ region), records a spec snapshot in meta['provider_spec'], and on
 * success kicks the on-box verification sweep (reachability probe + inventory
 * refresh) so live stats catch up in the same pass.
 */
class SyncServerProviderSpecsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $server = $this->server->fresh();
        if (! $server) {
            return;
        }

        $meta = $server->meta ?? [];

        try {
            $spec = $this->fetchSpec($server);
        } catch (\Throwable $e) {
            Log::warning('server.provider_spec_sync_failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
            $meta['provider_spec_error'] = [
                'message' => Str::limit($e->getMessage(), 300, '…'),
                'at' => now()->toIso8601String(),
            ];
            $server->update(['meta' => $meta]);

            return;
        }

        $previousSize = (string) $server->size;
        $sizeChanged = filled($spec['size']) && $spec['size'] !== $previousSize;

        $meta['provider_spec'] = [
            'size' => $spec['size'],
            'memory_mb' => $spec['memory_mb'],
            'vcpus' => $spec['vcpus'],
            'disk_gb' => $spec['disk_gb'],
            'synced_at' => now()->toIso8601String(),
            // Keep the pre-resize slug visible so the UI can say what changed.
            'size_changed_from' => $sizeChanged ? $previousSize : null,
        ];
        unset($meta['provider_spec_error']);

        $updates = ['meta' => $meta];
        if ($sizeChanged) {
            $updates['size'] = $spec['size'];
        }
        if (filled($spec['region']) && $spec['region'] !== $server->region) {
            $updates['region'] = $spec['region'];
        }

        $server->update($updates);

        // Provider facts are current — now verify the box itself: reachability
        // plus the SSH inventory probe, so on-box stats (memory, disk, services)
        // reflect the resized machine without waiting for the next cadence.
        if ($server->isReady() && ! empty($server->ip_address)) {
            CheckServerHealthJob::dispatch($server);
            RefreshServerInventoryJob::dispatch((string) $server->id);
        }
    }

    /**
     * Current hardware facts from the provider API.
     *
     * @return array{size: ?string, memory_mb: ?int, vcpus: ?int, disk_gb: ?int, region: ?string}
     *
     * @throws \RuntimeException when the server can't be looked up
     */
    private function fetchSpec(Server $server): array
    {
        $credential = $server->providerCredential;
        if (! $credential || blank($server->provider_id)) {
            throw new \RuntimeException('No provider credential or provider server id on record for this server.');
        }

        return match ($server->provider) {
            ServerProvider::DigitalOcean => $this->fromDigitalOcean($server, $credential),
            ServerProvider::Hetzner => $this->fromHetzner($server, $credential),
            ServerProvider::Aws => $this->fromAws($server, $credential),
            default => throw new \RuntimeException(sprintf(
                'Spec re-sync is not supported for the %s provider yet.',
                $server->provider->value,
            )),
        };
    }

    /** @return array{size: ?string, memory_mb: ?int, vcpus: ?int, disk_gb: ?int, region: ?string} */
    private function fromDigitalOcean(Server $server, mixed $credential): array
    {
        $droplet = (new DigitalOceanService($credential))->getDroplet((int) $server->provider_id);

        $sizeObj = is_array($droplet['size'] ?? null) ? $droplet['size'] : [];

        return [
            'size' => self::str($droplet['size_slug'] ?? $sizeObj['slug'] ?? null),
            'memory_mb' => self::int($droplet['memory'] ?? $sizeObj['memory'] ?? null),
            'vcpus' => self::int($droplet['vcpus'] ?? $sizeObj['vcpus'] ?? null),
            'disk_gb' => self::int($droplet['disk'] ?? $sizeObj['disk'] ?? null),
            'region' => self::str($droplet['region']['slug'] ?? null),
        ];
    }

    /** @return array{size: ?string, memory_mb: ?int, vcpus: ?int, disk_gb: ?int, region: ?string} */
    private function fromHetzner(Server $server, mixed $credential): array
    {
        $instance = (new HetznerService($credential))->getInstance((int) $server->provider_id);

        $type = is_array($instance['server_type'] ?? null) ? $instance['server_type'] : [];
        $memoryGb = $type['memory'] ?? null; // Hetzner reports GB (float)

        return [
            'size' => self::str($type['name'] ?? null),
            'memory_mb' => is_numeric($memoryGb) ? (int) round((float) $memoryGb * 1024) : null,
            'vcpus' => self::int($type['cores'] ?? null),
            'disk_gb' => self::int($type['disk'] ?? null),
            'region' => self::str($instance['datacenter']['location']['name'] ?? null),
        ];
    }

    /** @return array{size: ?string, memory_mb: ?int, vcpus: ?int, disk_gb: ?int, region: ?string} */
    private function fromAws(Server $server, mixed $credential): array
    {
        $region = $server->region ?: null;
        $instances = (new AwsEc2Service($credential, $region))->describeInstances((string) $server->provider_id);
        $instance = $instances[0] ?? null;
        if (! is_array($instance)) {
            throw new \RuntimeException('AWS did not return the instance — it may have been terminated.');
        }

        $cpu = is_array($instance['CpuOptions'] ?? null) ? $instance['CpuOptions'] : [];
        $vcpus = (is_numeric($cpu['CoreCount'] ?? null) && is_numeric($cpu['ThreadsPerCore'] ?? null))
            ? (int) $cpu['CoreCount'] * (int) $cpu['ThreadsPerCore']
            : null;

        return [
            'size' => self::str($instance['InstanceType'] ?? null),
            // EC2 doesn't return memory/disk on describeInstances; the on-box
            // inventory refresh dispatched after this sync fills the live view.
            'memory_mb' => null,
            'vcpus' => $vcpus,
            'disk_gb' => null,
            // Keep the stored region — AWS region is part of the credential/client
            // scope, not something a resize changes.
            'region' => null,
        ];
    }

    private static function str(mixed $value): ?string
    {
        return (is_string($value) && trim($value) !== '') ? trim($value) : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
