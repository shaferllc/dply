<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dply-managed Lookout error-tracking project — one row per project dply
 * mints on uselookout.app for a site (the "managed" account model). Billed per
 * tier while ACTIVE; the first project per org is free (see config('lookout')).
 *
 * Mirrors {@see \App\Modules\Realtime\Models\RealtimeApp}: a managed resource
 * whose lifecycle (active/paused) drives a per-tier Stripe line via
 * {@see \App\Modules\Billing\Services\OrganizationBillingStateComputer} and the
 * {@see \App\Observers\LookoutProjectBillingObserver}. The BYO account model
 * does not create these rows (the customer pays Lookout directly).
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $site_id
 * @property ?string $site_binding_id
 * @property ?string $lookout_project_id
 * @property string $name
 * @property string $tier
 * @property string $status
 * @property ?int $retention_days
 * @property ?string $error_message
 * @property array<string, mixed> $meta
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read ?Organization $organization
 */
class LookoutProject extends Model
{
    use HasUlids;

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PAUSED = 'paused';

    /** Provisioned free via the bundled-products perk — excluded from billing. */
    public const SOURCE_BUNDLE = 'bundle';

    protected $fillable = [
        'organization_id',
        'site_id',
        'site_binding_id',
        'lookout_project_id',
        'name',
        'tier',
        'status',
        'source',
        'retention_days',
        'error_message',
        'meta',
    ];

    /** A bundle-origin project is never billed (the free tracely+Lookout perk). */
    public function isBundle(): bool
    {
        return $this->source === self::SOURCE_BUNDLE;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The project's billing tier slug, falling back to the configured default
     * when the stored value isn't a known tier.
     */
    public function tierSlug(): string
    {
        $tier = (string) ($this->tier ?? '');
        $tiers = (array) config('lookout.tiers', []);

        return array_key_exists($tier, $tiers)
            ? $tier
            : (string) config('lookout.default_tier', 'starter');
    }

    /**
     * The resolved tier definition: ['label', 'retention_days', 'monthly_events', 'price_cents'].
     *
     * @return array{label: string, retention_days: int, monthly_events: int, price_cents: int}
     */
    public function tierConfig(): array
    {
        $tiers = (array) config('lookout.tiers', []);
        $tier = (array) ($tiers[$this->tierSlug()] ?? []);

        return [
            'label' => (string) ($tier['label'] ?? ucfirst($this->tierSlug())),
            'retention_days' => (int) ($tier['retention_days'] ?? 7),
            'monthly_events' => (int) ($tier['monthly_events'] ?? 0),
            'price_cents' => (int) ($tier['price_cents'] ?? 0),
        ];
    }

    /** Monthly price in cents for this project's tier. */
    public function priceCents(): int
    {
        return $this->tierConfig()['price_cents'];
    }
}
