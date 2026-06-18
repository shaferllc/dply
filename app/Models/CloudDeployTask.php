<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 *                      A one-shot task tied to a Cloud Site's deploy lifecycle — migrations
 *                      before traffic flips, cache warmers after rollout, cleanup on
 *                      failure, or ad-hoc commands an operator triggers from the dashboard.
 *                      Each row maps to a `jobs` component in the DigitalOcean App Platform
 *                      spec with `kind` matching the task's trigger. AWS App Runner doesn't
 *                      support jobs — task creation is rejected for App Runner sites.
 *                      The canonical migrations task is stored as a normal row (name=
 *                      'migrate', trigger='pre_deploy', command='php artisan migrate
 *                      --force'); the Create form's first-class "Run migrations" toggle is
 *                      just a thin convenience over this row.
 * @property string $command
 * @property array<string, mixed> $meta
 * @property string $name
 * @property ?string $site_id
 * @property string $size
 * @property string $status
 * @property string $trigger
 * @property-read ?Site $site
 * @property-read Collection<int, CloudDeployTaskRun> $runs
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class CloudDeployTask extends Model
{
    use HasUlids;

    public const TRIGGER_PRE_DEPLOY = 'pre_deploy';

    public const TRIGGER_POST_DEPLOY = 'post_deploy';

    public const TRIGGER_FAILED_DEPLOY = 'failed_deploy';

    public const TRIGGER_MANUAL = 'manual';

    public const STATUS_CONFIGURED = 'configured';

    public const STATUS_DELETING = 'deleting';

    /** Sentinel name used for the first-class migrations task. */
    public const NAME_MIGRATE = 'migrate';

    /** Default command pre-filled into the migrations field in source mode. */
    public const DEFAULT_MIGRATE_COMMAND = 'php artisan migrate --force';

    /**
     * Map of dply triggers → DO App Platform job `kind` strings.
     *
     * @var array<string, string>
     */
    public const DO_KIND_MAP = [
        self::TRIGGER_PRE_DEPLOY => 'PRE_DEPLOY',
        self::TRIGGER_POST_DEPLOY => 'POST_DEPLOY',
        self::TRIGGER_FAILED_DEPLOY => 'FAILED_DEPLOY',
        self::TRIGGER_MANUAL => 'MANUAL',
    ];

    /**
     * Portable size tier → DO App Platform instance_size_slug. Mirrors
     * the container size_tier mapping in DigitalOceanAppPlatformBackend
     * so a task can run on the same compute class as the site, plus
     * the Professional variants for autoscaling-aware deploys.
     *
     * @var array<string, string>
     */
    public const SIZE_TIERS = [
        'small' => 'basic-xxs',
        'medium' => 'basic-xs',
        'large' => 'basic-s',
        'xlarge' => 'basic-m',
        'small-pro' => 'apps-d-1vcpu-0.5gb',
        'medium-pro' => 'apps-d-1vcpu-1gb',
        'large-pro' => 'apps-d-1vcpu-2gb',
        'xlarge-pro' => 'apps-d-2vcpu-4gb',
    ];

    protected $fillable = [
        'site_id',
        'trigger',
        'name',
        'command',
        'size',
        'status',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<CloudDeployTaskRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(CloudDeployTaskRun::class);
    }

    public function isManual(): bool
    {
        return $this->trigger === self::TRIGGER_MANUAL;
    }

    public function isMigration(): bool
    {
        return $this->name === self::NAME_MIGRATE;
    }

    /**
     * DO App Platform `kind` value for this task's trigger.
     */
    public function doKind(): string
    {
        return self::DO_KIND_MAP[$this->trigger] ?? 'PRE_DEPLOY';
    }

    /**
     * Map this task's portable size tier to the DO App Platform
     * instance_size_slug. Unknown tiers fall back to the smallest.
     */
    public function backendSizeSlug(): string
    {
        return self::SIZE_TIERS[$this->size] ?? self::SIZE_TIERS['small'];
    }
}
