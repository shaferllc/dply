<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?Carbon $cutover_completed_at
 * @property ?Carbon $cutover_started_at
 * @property string $domain
 * @property string $failure_summary
 * @property ?string $import_server_migration_id
 * @property string $site_type
 * @property string $source
 * @property int $source_site_id
 * @property array<string, mixed> $source_snapshot
 * @property string $ssl_strategy
 * @property ?Carbon $staging_completed_at
 * @property string $status
 * @property ?string $target_site_id
 * @property-read ?ImportServerMigration $serverMigration
 * @property-read ?Site $targetSite
 * @property-read Collection<int, ImportMigrationStep> $steps
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ImportSiteMigration extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_STAGING = 'staging';

    public const STATUS_READY_FOR_CUTOVER = 'ready_for_cutover';

    public const STATUS_CUTOVER_IN_PROGRESS = 'cutover_in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABORTED = 'aborted';

    public const STATUS_CUTOVER_FAILED = 'cutover_failed';

    public const STATUS_CUTOVER_ROLLED_BACK = 'cutover_rolled_back';

    /** SSL strategy auto-selected per Q9b. */
    public const SSL_CLEAN = 'clean';

    public const SSL_BRIDGED = 'bridged';

    public const SSL_GAP = 'gap';

    protected $fillable = [
        'import_server_migration_id',
        'source',
        'source_site_id',
        'target_site_id',
        'domain',
        'site_type',
        'status',
        'ssl_strategy',
        'source_snapshot',
        'staging_completed_at',
        'cutover_started_at',
        'cutover_completed_at',
        'failure_summary',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_site_id' => 'integer',
            'source_snapshot' => 'array',
            'staging_completed_at' => 'datetime',
            'cutover_started_at' => 'datetime',
            'cutover_completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ImportServerMigration, $this> */
    public function serverMigration(): BelongsTo
    {
        return $this->belongsTo(ImportServerMigration::class, 'import_server_migration_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function targetSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'target_site_id');
    }

    /** @return HasMany<ImportMigrationStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ImportMigrationStep::class)->orderBy('sequence');
    }
}
