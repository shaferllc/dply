<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * A background process attached to a Cloud container Site — a queue
 * worker or the Laravel scheduler.
 *
 * On DigitalOcean App Platform each CloudWorker becomes a `workers`
 * component in the site's app spec, built from the same source/image
 * as the web `service`. The `command` is the component's run_command;
 * the scheduler is a worker whose command runs `php artisan
 * schedule:work` (App Platform has no native cron) and which is always
 * pinned to a single instance.
 *
 * AWS App Runner cannot run background processes — worker creation is
 * rejected for App Runner sites via CloudBackend::supportsWorkers().
 */
class CloudWorker extends Model
{
    /** @use HasFactory<CloudWorkerFactory> */
    use HasFactory, HasUlids;

    public const TYPE_WORKER = 'worker';

    public const TYPE_SCHEDULER = 'scheduler';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETING = 'deleting';

    /** Default command for a queue worker when none is supplied. */
    public const DEFAULT_WORKER_COMMAND = 'php artisan queue:work';

    /** The command the scheduler-type worker always runs. */
    public const SCHEDULER_COMMAND = 'php artisan schedule:work';

    /**
     * Portable size tier → DO App Platform instance_size_slug. Mirrors
     * the container size_tier mapping in DigitalOceanAppPlatformBackend.
     *
     * @var array<string, string>
     */
    public const SIZE_TIERS = [
        'small' => 'basic-xxs',
        'medium' => 'basic-xs',
        'large' => 'basic-s',
        'xlarge' => 'basic-m',
    ];

    /**
     * DigitalOcean App Platform caps fixed instance_count on worker
     * components for some size slugs — basic-xxs (our "small" tier)
     * allows only a single instance.
     *
     * @var array<string, int>
     */
    public const MAX_INSTANCES_BY_SIZE = [
        'small' => 1,
        'medium' => 50,
        'large' => 50,
        'xlarge' => 50,
    ];

    protected $fillable = [
        'site_id',
        'type',
        'name',
        'command',
        'size',
        'instance_count',
        'status',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'instance_count' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo {
        return $this->belongsTo(Site::class);
    }

    public function isScheduler(): bool
    {
        return $this->type === self::TYPE_SCHEDULER;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The command the backend component should run. A scheduler always
     * runs the Laravel scheduler loop regardless of any stored command;
     * a worker runs its stored command, falling back to the default
     * queue:work loop when none was configured.
     */
    public function effectiveCommand(): string
    {
        if ($this->isScheduler()) {
            return self::SCHEDULER_COMMAND;
        }

        $command = trim((string) $this->command);

        return $command !== '' ? $command : self::DEFAULT_WORKER_COMMAND;
    }

    public static function maxInstanceCountForSize(string $size): int
    {
        $size = strtolower(trim($size));

        return self::MAX_INSTANCES_BY_SIZE[$size] ?? self::MAX_INSTANCES_BY_SIZE['small'];
    }

    public function maxInstanceCount(): int
    {
        return self::maxInstanceCountForSize((string) $this->size);
    }

    /**
     * Clamp a requested worker instance count to what the size tier
     * allows on DigitalOcean App Platform.
     */
    public static function normalizeInstanceCount(string $size, int $count, bool $isScheduler = false): int
    {
        if ($isScheduler || $count < 1) {
            return 1;
        }

        return min(max(1, $count), self::maxInstanceCountForSize($size));
    }

    /**
     * The instance count the backend component should run. A scheduler
     * MUST be a single instance — running the scheduler loop on more
     * than one process would dispatch every scheduled task N times.
     */
    public function effectiveInstanceCount(): int
    {
        if ($this->isScheduler()) {
            return 1;
        }

        return self::normalizeInstanceCount((string) $this->size, (int) $this->instance_count);
    }

    /**
     * Map this worker's portable size tier to the DO App Platform
     * instance_size_slug. Unknown tiers fall back to the smallest.
     */
    public function backendSizeSlug(): string
    {
        return self::SIZE_TIERS[$this->size] ?? self::SIZE_TIERS['small'];
    }
}
