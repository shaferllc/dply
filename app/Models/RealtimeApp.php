<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Realtime\Services\RealtimeBackend;
use Database\Factories\RealtimeAppFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 *                      A managed realtime app — a Pusher/Reverb-compatible channel application that
 *                      runs on the dply realtime Worker (packages/realtime-worker). One row per app;
 *                      the row's ULID is the Worker-side `app_id` used for Durable Object routing,
 *                      publishing, and stats. `app_key` is the public connection key and `app_secret`
 *                      signs channel auth + REST publishes.
 *                      Provisioning writes the credentials into the Worker's APPS KV namespace via a
 *                      {@see RealtimeBackend}. Billed per active app by connection tier — see
 *                      config('realtime.tiers') and {@see tierConfig()}.
 * @property string $app_key
 * @property string $app_secret
 * @property string $backend
 * @property ?string $error_message
 * @property string $host
 * @property ?Carbon $last_stats_at
 * @property int $max_connections
 * @property array<string, mixed> $meta
 * @property string $name
 * @property ?string $organization_id
 * @property int $peak_connections
 * @property string $status
 * @property string $tier
 * @property-read ?Organization $organization
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class RealtimeApp extends Model
{
    /** @use HasFactory<RealtimeAppFactory> */
    use HasFactory, HasUlids;

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'organization_id',
        'name',
        'app_key',
        'app_secret',
        'status',
        'backend',
        'tier',
        'host',
        'max_connections',
        'peak_connections',
        'last_stats_at',
        'error_message',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'max_connections' => 'integer',
            'peak_connections' => 'integer',
            'last_stats_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Fresh public key + signing secret for a new app. The key is the public
     * identifier clients connect with; the secret signs channel auth + publishes.
     *
     * @return array{app_key: string, app_secret: string}
     */
    public static function generateCredentials(): array
    {
        return [
            'app_key' => 'rtk_'.Str::random(24),
            'app_secret' => 'rts_'.Str::random(40),
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Counts toward the bill — an active, dply-managed app. */
    public function isBillable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** The app's connection tier slug, falling back to the configured default. */
    public function tierSlug(): string
    {
        $tier = (string) ($this->tier ?? '');
        $tiers = (array) config('realtime.tiers', []);

        return array_key_exists($tier, $tiers)
            ? $tier
            : (string) config('realtime.default_tier', 'starter');
    }

    /**
     * The resolved tier definition: ['label', 'max_connections', 'price_cents'].
     *
     * @return array{label: string, max_connections: int, price_cents: int}
     */
    public function tierConfig(): array
    {
        $tiers = (array) config('realtime.tiers', []);
        $tier = (array) ($tiers[$this->tierSlug()] ?? []);

        return [
            'label' => (string) ($tier['label'] ?? ucfirst($this->tierSlug())),
            'max_connections' => (int) ($tier['max_connections'] ?? config('realtime.plan.max_connections')),
            'price_cents' => (int) ($tier['price_cents'] ?? config('realtime.plan.price_cents')),
        ];
    }

    /** Hard connection cap for this app, derived from its tier. */
    public function maxConnections(): int
    {
        // An explicitly-stored override wins (e.g. a comped/custom app); else the
        // tier's cap. Keeps max_connections in sync with the tier on provision.
        return (int) ($this->max_connections ?: $this->tierConfig()['max_connections']);
    }

    /** Monthly price in cents for this app's tier. */
    public function priceCents(): int
    {
        return $this->tierConfig()['price_cents'];
    }

    public function host(): string
    {
        $configured = (string) ($this->getAttributes()['host'] ?? '');

        return $configured !== ''
            ? $configured
            : (string) config('realtime.host');
    }

    public function websocketUrl(): string
    {
        return 'wss://'.$this->host().'/app/'.$this->app_key;
    }

    public function publishEndpoint(): string
    {
        return 'https://'.$this->host().'/apps/'.$this->id.'/events';
    }

    public function statsEndpoint(): string
    {
        return 'https://'.$this->host().'/apps/'.$this->id.'/stats';
    }

    /**
     * Operator/server-to-server header auth for the Worker stats endpoint.
     *
     * @return array<string, string>
     */
    public function statsAuthHeaders(): array
    {
        return [
            'X-Dply-Key' => (string) $this->app_key,
            'X-Dply-Secret' => (string) $this->app_secret,
        ];
    }

    public function kvKeyByKey(): string
    {
        return 'key:'.$this->app_key;
    }

    public function kvKeyById(): string
    {
        return 'id:'.$this->id;
    }

    /**
     * The credential record the realtime Worker reads from KV.
     *
     * @return array<string, mixed>
     */
    public function kvRecord(): array
    {
        return [
            'id' => (string) $this->id,
            'key' => (string) $this->app_key,
            'secret' => (string) $this->app_secret,
            'enabled' => $this->status === self::STATUS_ACTIVE,
            'maxConnections' => $this->maxConnections(),
        ];
    }
}
