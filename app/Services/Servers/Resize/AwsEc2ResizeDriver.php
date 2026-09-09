<?php

declare(strict_types=1);

namespace App\Services\Servers\Resize;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Modules\Providers\Services\AwsEc2Service;
use App\Modules\Providers\Services\AwsEc2ServiceFactory;
use App\Services\Servers\Resize\Concerns\BuildsResizeOptions;

/**
 * EC2 instances. Resize is ModifyInstanceAttribute(InstanceType) on a stopped
 * instance.
 *
 * The important difference from every other driver: EC2 does not tie storage
 * to the instance type. The root volume is a separate EBS resource that is not
 * touched here, so `disk_gb` is always null, `grows_disk` is always false, and
 * an EC2 resize is always reversible. Growing the disk is a distinct operation
 * (ModifyVolume + an on-box filesystem grow) and deliberately not part of this.
 *
 * Pricing is also absent: EC2 rates vary by region, tenancy, OS and any
 * Savings Plan on the account, so quoting a monthly figure from the instance
 * type alone would be wrong more often than right.
 */
class AwsEc2ResizeDriver implements ServerResizeDriver
{
    use BuildsResizeOptions;

    public function __construct(
        private AwsEc2ServiceFactory $factory,
    ) {}

    public function supports(Server $server): bool
    {
        return $server->provider === ServerProvider::Aws
            && $server->providerCredential !== null
            && filled($server->provider_id);
    }

    public function requiresPowerCycle(): bool
    {
        return true;
    }

    public function catalog(Server $server): array
    {
        $ec2 = $this->service($server);

        $instances = $ec2->describeInstances((string) $server->provider_id);
        $instance = reset($instances) ?: [];

        $currentSlug = self::str($instance['InstanceType'] ?? null);
        $currentMemory = 0;
        $currentVcpus = null;

        $available = $ec2->describeAvailableInstanceTypes();

        // DescribeInstances reports the type but not its size, so the current
        // machine's vCPU/memory come from the same catalog as the targets.
        foreach ($available as $type) {
            if ($type['slug'] === $currentSlug) {
                $currentVcpus = $type['vcpus'];
                $currentMemory = $type['memory_mb'];
                break;
            }
        }

        $current = [
            'slug' => $currentSlug,
            'vcpus' => $currentVcpus,
            'memory_mb' => $currentMemory !== 0 ? $currentMemory : null,
            'disk_gb' => null,
            'region' => self::str($instance['Placement']['AvailabilityZone'] ?? null),
        ];

        $options = [];
        foreach ($available as $type) {
            if ($type['slug'] === $currentSlug) {
                continue;
            }

            $options[] = $this->option(
                $type['slug'],
                $type['vcpus'],
                $type['memory_mb'],
                null,
                null,
                null,
                $currentMemory,
            );
        }

        return ['current' => $current, 'options' => $this->sortOptions($options)];
    }

    public function execute(Server $server, array $target, callable $progress): void
    {
        $ec2 = $this->service($server);
        $instanceId = (string) $server->provider_id;

        $progress('powering_off');
        $ec2->stopInstanceAndWait($instanceId);

        $progress('resizing');
        $ec2->modifyInstanceType($instanceId, $target['slug']);

        $progress('powering_on');
        $ec2->startInstanceAndWait($instanceId);
    }

    /**
     * EC2 clients are region-bound, so the service has to be built against the
     * region the server actually lives in rather than the credential default.
     */
    private function service(Server $server): AwsEc2Service
    {
        // Through the factory rather than `new` so the SDK client can be
        // swapped in tests — the same seam the provisioning path uses.
        return $this->factory->make($server->providerCredential, $server->region ?: null);
    }
}
