<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function serverMigration(): BelongsTo
    {
        return $this->belongsTo(ImportServerMigration::class, 'import_server_migration_id');
    }

    public function targetSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'target_site_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ImportMigrationStep::class)->orderBy('sequence');
    }
}
