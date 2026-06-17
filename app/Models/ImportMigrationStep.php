<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $attempts
 * @property ?string $error_message
 * @property ?Carbon $finished_at
 * @property ?string $import_server_migration_id
 * @property ?string $import_site_migration_id
 * @property string $log_object_key
 * @property array<string, mixed> $result_data
 * @property string $sequence
 * @property ?Carbon $started_at
 * @property string $status
 * @property string $step_key
 * @property-read ?ImportServerMigration $serverMigration
 * @property-read ?ImportSiteMigration $siteMigration
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
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

    public const KEY_COLLECT_MANUAL_REVIEW = 'collect_manual_review';

    public const KEY_REVOKE_SSH_KEY = 'revoke_ssh_key';

    // Per-site step keys (declared upfront for the UI).
    public const KEY_FREEZE_SNAPSHOT = 'freeze_snapshot';

    public const KEY_CREATE_TARGET_SITE = 'create_target_site';

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

    /**
     * Step keys the user may Skip when failed. Per Q13 — DB and code-clone
     * are load-bearing (the migration is meaningless without them); cutover
     * steps are the cutover dance and can't be partially executed. Cron /
     * daemon / scheduler / SSL setup are recreatable in dply UI later, so a
     * skip just means "I'll fix it manually post-migration."
     */
    public const SKIPPABLE_KEYS = [
        self::KEY_RECREATE_CRONS,
        self::KEY_RECREATE_DAEMONS,
        self::KEY_RECREATE_SCHEDULER,
        self::KEY_SETUP_SSL,
        self::KEY_COLLECT_MANUAL_REVIEW,
    ];

    public function isSkippable(): bool
    {
        return in_array($this->step_key, self::SKIPPABLE_KEYS, true);
    }

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'result_data' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ImportServerMigration, $this> */
    public function serverMigration(): BelongsTo
    {
        return $this->belongsTo(ImportServerMigration::class, 'import_server_migration_id');
    }

    /** @return BelongsTo<ImportSiteMigration, $this> */
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
