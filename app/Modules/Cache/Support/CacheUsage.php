<?php

declare(strict_types=1);

namespace App\Modules\Cache\Support;

/**
 * What one cache currently occupies.
 *
 * Read from `dply_cache_usage`, which lives beside the items on the
 * `dply_cache` connection rather than on the `managed_caches` row — see that
 * migration for why. A cache that has never been written to has no usage row,
 * which is {@see empty()} rather than an error.
 */
final readonly class CacheUsage
{
    public function __construct(
        public int $residentBytes = 0,
        public int $itemCount = 0,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function isOverQuota(int $quotaBytes): bool
    {
        return $quotaBytes > 0 && $this->residentBytes >= $quotaBytes;
    }

    /** 0.0-1.0, for the usage meter on the show page. */
    public function fractionOf(int $quotaBytes): float
    {
        return $quotaBytes > 0 ? min(1.0, $this->residentBytes / $quotaBytes) : 0.0;
    }
}
