<?php

declare(strict_types=1);

namespace App\Modules\Cache\Support;

use stdClass;

/**
 * One cache item, in the store's terms.
 *
 * `type` is DynamoDB's AttributeValue discriminator (S | N | B) and is carried
 * end to end rather than inferred. `DynamoDbStore::get()` reads the value back
 * as `['S'] ?? ['N']`, and Laravel's own `type()` returns 'N' for numerics — so
 * a value stored as a number and returned as a string would come back through
 * `unserialize()` as the wrong PHP type. Round-tripping the discriminator is
 * what keeps `Cache::get()` returning what `Cache::put()` was given.
 */
final readonly class CacheItem
{
    public function __construct(
        public string $key,
        public ?string $value,
        public string $type,
        public int $expiresAt,
    ) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(
            key: (string) $row->key,
            value: $row->value === null ? null : (string) $row->value,
            type: (string) ($row->value_type ?: 'S'),
            expiresAt: (int) $row->expires_at,
        );
    }

    /**
     * What this item costs against the quota.
     *
     * Key plus value, in bytes. Row overhead, index entries, and Postgres
     * per-tuple cost are deliberately not modelled: the quota exists to bound
     * growth, and a number a customer can reconcile against what they stored
     * is worth more than one that is accurate to the page.
     */
    public function byteSize(): int
    {
        return strlen($this->key) + strlen((string) $this->value);
    }

    /** The AttributeValue map this item serialises back to on the wire. */
    public function toAttributeValue(): array
    {
        return [$this->type => (string) $this->value];
    }
}
