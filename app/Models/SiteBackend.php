<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * One serving point of a multi-backend Site: the logical Site → a backend app
 * server (and the derived child Site holding the code there). The prerequisite
 * for rolling + canary deploys. See docs/MULTI_BACKEND_SITES.md.
 */
class SiteBackend extends Model
{
    use HasUlids;

    public const ROLE_PRIMARY = 'primary';

    public const ROLE_REPLICA = 'replica';

    // Lifecycle — mirrors WorkerPool member states so the replica-cloning
    // machinery can drive both.
    public const STATE_PROVISIONING = 'provisioning';

    public const STATE_REPLAYING = 'replaying';

    public const STATE_DEPLOYING = 'deploying';

    public const STATE_ACTIVE = 'active';

    public const STATE_DRAINING = 'draining';

    public const STATE_ERRORED = 'errored';

    protected $fillable = [
        'site_id',
        'server_id',
        'backend_site_id',
        'role',
        'weight',
        'state',
        'drained_at',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'drained_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** The logical Site that owns the backend group. *
 * @return BelongsTo<Site, $this>
 */
    public function site(): BelongsTo {
        return $this->belongsTo(Site::class);
    }

    /** The app server this backend runs on. *
 * @return BelongsTo<Server, $this>
 */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /** The derived child Site holding the code on the backend server (nullable). *
 * @return BelongsTo<Site, $this>
 */
    public function backendSite(): BelongsTo {
        return $this->belongsTo(Site::class, 'backend_site_id');
    }

    public function isPrimary(): bool
    {
        return $this->role === self::ROLE_PRIMARY;
    }

    /** In rotation = active and not currently drained for a rolling step. */
    public function isInRotation(): bool
    {
        return $this->state === self::STATE_ACTIVE && $this->drained_at === null;
    }
}
