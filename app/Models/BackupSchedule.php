<?php

namespace App\Models;

use App\Models\Concerns\DescribesCronExpression;
use App\Modules\Backups\Console\DispatchDueBackupSchedulesCommand;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recurring capture, whatever it captures.
 *
 * Was `ServerBackupSchedule` (dumps + file archives) alongside a column-for-column
 * clone called `RedisSnapshotSchedule`. They are one table now because
 * docs/adr/backups-as-a-product.md decision 4 makes "distinct protected target"
 * the unit an invoice is computed from, and decision 8 refuses to derive that
 * number from a UNION across tables that drift.
 *
 * The name lost its `Server` prefix deliberately: `Site.server_id` is nullable,
 * so a schedule can protect something that lives on no server at all.
 *
 * A schedule is a row, not a cron line — {@see DispatchDueBackupSchedulesCommand}
 * ticks it from the control plane. See that class for why.
 *
 * @property string $id
 * @property ?string $backup_configuration_id
 * @property string $cron_expression
 * @property bool $is_active
 * @property ?Carbon $last_run_at
 * @property bool $notify_on_failure
 * @property ?string $server_id
 * @property ?string $target_id
 * @property string $target_type
 * @property-read ?Server $server
 * @property-read ?BackupConfiguration $backupConfiguration
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class BackupSchedule extends Model
{
    use DescribesCronExpression, HasUlids;

    /** SQL dump of one {@see ServerDatabase}. */
    public const TARGET_DATABASE = 'database';

    /** tar.gz of one {@see Site}'s repository root. */
    public const TARGET_SITE_FILES = 'site_files';

    /** RDB snapshot of one {@see ServerCacheService}. */
    public const TARGET_CACHE = 'cache';

    /** Full-disk provider image of one {@see Server}. Wired in M2. */
    public const TARGET_SERVER_IMAGE = 'server_image';

    protected $table = 'backup_schedules';

    protected $fillable = [
        'server_id',
        'target_type',
        'target_id',
        'backup_configuration_id',
        'cron_expression',
        'is_active',
        'notify_on_failure',
        'last_run_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'notify_on_failure' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<BackupConfiguration, $this> */
    public function backupConfiguration(): BelongsTo
    {
        return $this->belongsTo(BackupConfiguration::class);
    }

    /**
     * The cache service this schedule snapshots. Only meaningful when
     * `target_type` is {@see TARGET_CACHE} — `target_id` points at a different
     * table for every other kind, so eager-loading this on a mixed set resolves
     * to null for the rest. Kept as a relation (rather than only {@see target()})
     * so the cache tab can eager-load it for a list.
     *
     * @return BelongsTo<ServerCacheService, $this>
     */
    public function cacheService(): BelongsTo
    {
        return $this->belongsTo(ServerCacheService::class, 'target_id');
    }

    /**
     * Resolve the polymorphic target.
     */
    public function target(): ?Model
    {
        return match ($this->target_type) {
            self::TARGET_DATABASE => ServerDatabase::query()->find($this->target_id),
            self::TARGET_SITE_FILES => Site::query()->find($this->target_id),
            self::TARGET_CACHE => ServerCacheService::query()->find($this->target_id),
            self::TARGET_SERVER_IMAGE => Server::query()->find($this->target_id),
            default => null,
        };
    }

    public function targetLabel(): string
    {
        $target = $this->target();
        if ($target === null) {
            return '(missing)';
        }

        return match ($this->target_type) {
            self::TARGET_DATABASE => $target->name ?? '(unnamed database)',
            self::TARGET_SITE_FILES => $target->name ?? '(unnamed site)',
            self::TARGET_CACHE => $target->name ?? $target->engine ?? '(cache service)',
            self::TARGET_SERVER_IMAGE => $target->name ?? '(unnamed server)',
            default => '(unknown)',
        };
    }

    /** Short badge text for the capture kind, e.g. "DB" / "Files" / "Cache". */
    public function targetKindLabel(): string
    {
        return match ($this->target_type) {
            self::TARGET_DATABASE => __('DB'),
            self::TARGET_SITE_FILES => __('Files'),
            self::TARGET_CACHE => __('Cache'),
            self::TARGET_SERVER_IMAGE => __('Image'),
            default => __('Unknown'),
        };
    }
}
