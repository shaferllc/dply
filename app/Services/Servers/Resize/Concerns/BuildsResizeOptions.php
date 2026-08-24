<?php

declare(strict_types=1);

namespace App\Services\Servers\Resize\Concerns;

/**
 * Shared shaping for driver catalogs: the rules that are the same everywhere
 * (a disk never shrinks, the current size is not a target, smallest first)
 * live here so each driver only has to translate its provider's payload.
 */
trait BuildsResizeOptions
{
    /**
     * @return array{
     *     slug: string,
     *     vcpus: int,
     *     memory_mb: int,
     *     disk_gb: int|null,
     *     price_monthly: float|null,
     *     grows_disk: bool,
     *     direction: 'up'|'down'|'same'
     * }
     */
    protected function option(
        string $slug,
        int $vcpus,
        int $memoryMb,
        ?int $diskGb,
        ?float $priceMonthly,
        ?int $currentDiskGb,
        int $currentMemoryMb,
    ): array {
        return [
            'slug' => $slug,
            'vcpus' => $vcpus,
            'memory_mb' => $memoryMb,
            'disk_gb' => $diskGb,
            'price_monthly' => $priceMonthly,
            // Null disk means the provider does not tie disk to the plan at all
            // (EC2: the root volume is a separate EBS resource), so nothing can
            // grow irreversibly here.
            'grows_disk' => $diskGb !== null && $currentDiskGb !== null && $diskGb > $currentDiskGb,
            'direction' => match (true) {
                $memoryMb > $currentMemoryMb => 'up',
                $memoryMb < $currentMemoryMb => 'down',
                default => 'same',
            },
        ];
    }

    /**
     * A plan whose disk is smaller than the machine's current disk is not a
     * resize target on any provider — disks only grow.
     */
    protected function diskCanHold(?int $candidateDiskGb, ?int $currentDiskGb): bool
    {
        if ($candidateDiskGb === null || $currentDiskGb === null) {
            return true;
        }

        return $candidateDiskGb >= $currentDiskGb;
    }

    /**
     * @param  list<array{memory_mb: int, disk_gb: int|null, ...}>  $options
     * @return list<array{memory_mb: int, disk_gb: int|null, ...}>
     */
    protected function sortOptions(array $options): array
    {
        usort(
            $options,
            fn (array $a, array $b): int => [$a['memory_mb'], $a['disk_gb'] ?? 0] <=> [$b['memory_mb'], $b['disk_gb'] ?? 0],
        );

        return $options;
    }

    protected static function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    protected static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    protected static function float(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }
}
