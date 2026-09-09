<?php

declare(strict_types=1);

namespace App\Modules\Cache\Support;

/**
 * What one organization may do with dply Cache.
 *
 * Note `maxCaches` is `?int` where the queue's equivalent is a plain int:
 * `null` means unlimited, said out loud. The queue's config has `business =>
 * max_namespaces: 0` next to `free => 1` with no stated sentinel, so it is
 * genuinely unclear whether the largest plan gets unlimited namespaces or none.
 * That ambiguity is not copied here.
 */
final readonly class CacheEntitlement
{
    public function __construct(
        public string $planKey,
        public bool $available,
        public ?int $maxCaches,
    ) {}

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $override
     */
    public static function fromConfig(string $planKey, array $defaults, array $override): self
    {
        $merged = array_merge($defaults, $override);

        $max = $merged['max_caches'] ?? null;

        return new self(
            planKey: $planKey,
            available: (bool) ($merged['available'] ?? false),
            maxCaches: $max === null ? null : (int) $max,
        );
    }

    public function allowsAnother(int $current): bool
    {
        if (! $this->available) {
            return false;
        }

        return $this->maxCaches === null || $current < $this->maxCaches;
    }

    /** For the "you are at your limit" hint; null when there is no ceiling. */
    public function limitLabel(): ?string
    {
        return $this->maxCaches === null ? null : (string) $this->maxCaches;
    }
}
