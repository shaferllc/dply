<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportMigrationStep extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    // Server-level step keys.
    public const KEY_PUSH_SSH_KEY = 'push_ssh_key';

    public const KEY_ELIGIBILITY_SCAN = 'eligibility_scan';

    public const KEY_REVOKE_SSH_KEY = 'revoke_ssh_key';

    // Per-site step keys (declared upfront for the UI).
    public const KEY_FREEZE_SNAPSHOT = 'freeze_snapshot';

    public const KEY_CLONE_REPO = 'clone_repo';

    public const KEY_COPY_ENV = 'copy_env';

    public const KEY_DUMP_DB = 'dump_database';

    public const KEY_RESTORE_DB = 'restore_database';

    public const KEY_RECREATE_CRONS = 'recreate_crons';

    public const KEY_RECREATE_DAEMONS = 'recreate_daemons';

    public const KEY_RECREATE_SCHEDULER = 'recreate_scheduler';

    public const KEY_SETUP_SSL = 'setup_ssl';

    public const KEY_CUTOVER_MAINTENANCE_ON = 'cutover_maintenance_on';

    public const KEY_CUTOVER_DB_DELTA = 'cutover_db_delta';

    public const KEY_CUTOVER_DNS_SWAP = 'cutover_dns_swap';

    public const KEY_CUTOVER_WEBHOOK_SWAP = 'cutover_webhook_swap';

    public const KEY_CUTOVER_SMOKE_TEST = 'cutover_smoke_test';

    protected $fillable = [
        'import_server_migration_id',
        'import_site_migration_id',
        'sequence',
        'step_key',
        'status',
        'attempts',
        'error_message',
        'log_object_key',
        'result_data',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'result_data' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function serverMigration(): BelongsTo
    {
        return $this->belongsTo(ImportServerMigration::class, 'import_server_migration_id');
    }

    public function siteMigration(): BelongsTo
    {
        return $this->belongsTo(ImportSiteMigration::class, 'import_site_migration_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
        ], true);
    }
}
