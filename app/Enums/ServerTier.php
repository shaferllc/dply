<?php

namespace App\Enums;

/**
 * Billing tier for a server, derived from its detected vCPU and RAM.
 *
 * Tiers are dply's own pricing buckets — they intentionally do not track any
 * specific provider's catalog (DO, Hetzner, AWS, Custom SSH all map into the
 * same five tiers). Tier prices live in config('subscription.standard.tiers');
 * the enum itself only knows the ordering and labels.
 */
enum ServerTier: string
{
    case XS = 'xs';
    case S = 's';
    case M = 'm';
    case L = 'l';
    case XL = 'xl';

    public function label(): string
    {
        return strtoupper($this->value);
    }

    /**
     * Tier price for the current configured plan, in cents.
     *
     * Reads from config('subscription.standard.tiers'). Returns 0 if the tier
     * is missing — callers should treat that as a configuration error, not a
     * free server.
     */
    public function priceCents(): int
    {
        return (int) (config('subscription.standard.tiers.'.$this->value) ?? 0);
    }

    /**
     * Numeric weight used for ordering and "highest tier up to M" credit lookups.
     */
    public function weight(): int
    {
        return match ($this) {
            self::XS => 1,
            self::S => 2,
            self::M => 3,
            self::L => 4,
            self::XL => 5,
        };
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::XS, self::S, self::M, self::L, self::XL];
    }
}
