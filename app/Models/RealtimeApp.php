<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A managed realtime app — a Pusher/Reverb-compatible channel application that
 * runs on the dply realtime Worker (packages/realtime-worker). One row per app;
 * the row's ULID is the Worker-side `app_id` used for Durable Object routing,
 * publishing, and stats. `app_key` is the public connection key and `app_secret`
 * signs channel auth + REST publishes.
 *
 * Provisioning writes the credentials into the Worker's APPS KV namespace via a
 * {@see \App\Services\Realtime\RealtimeBackend}. Billed flat per active app — see
 * config('realtime.plan.price_cents').
 */
class RealtimeApp extends Model
{
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
        'host',
        'max_connections',
        'peak_connections',
        'last_stats_at',
        'error_message',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Fresh public key + signing secret for a new app. The key is the public
     * identifier clients connect with; the secret signs channel auth + publishes.
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

    public function host(): string
    {
        return $this->host !== null && $this->host !== ''
            ? $this->host
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
            'maxConnections' => (int) ($this->max_connections ?? config('realtime.plan.max_connections')),
        ];
    }
}
