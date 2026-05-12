<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerBackupSchedule extends Model
{
    use HasUlids;

    public const TARGET_DATABASE = 'database';

    public const TARGET_SITE_FILES = 'site_files';

    protected $table = 'server_backup_schedules';

    protected $fillable = [
        'server_id',
        'target_type',
        'target_id',
        'backup_configuration_id',
        'cron_expression',
        'is_active',
        'notify_on_failure',
        'server_cron_job_id',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'notify_on_failure' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function backupConfiguration(): BelongsTo
    {
        return $this->belongsTo(BackupConfiguration::class);
    }

    public function serverCronJob(): BelongsTo
    {
        return $this->belongsTo(ServerCronJob::class);
    }

    /**
     * Resolve the polymorphic target — either {@see ServerDatabase} or {@see Site}.
     */
    public function target(): ?Model
    {
        return match ($this->target_type) {
            self::TARGET_DATABASE => ServerDatabase::query()->find($this->target_id),
            self::TARGET_SITE_FILES => Site::query()->find($this->target_id),
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
            default => '(unknown)',
        };
    }
}
