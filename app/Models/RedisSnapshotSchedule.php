<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DescribesCronExpression;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cron-driven schedule that fires `php artisan dply:run-redis-snapshot-schedule {schedule}`
 * on the control plane, which dispatches {@see App\Jobs\ExportRedisSnapshotJob}.
 *
 * One schedule per cache service (server_cache_service_id is unique). Operators
 * who want multiple cadences split into separate schedules via duplicate cache
 * services on the same host, which is rare enough to defer.
 */
class RedisSnapshotSchedule extends Model
{
    use DescribesCronExpression, HasUlids;

    protected $table = 'redis_snapshot_schedules';

    protected $fillable = [
        'server_id',
        'server_cache_service_id',
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

    public function cacheService(): BelongsTo
    {
        return $this->belongsTo(ServerCacheService::class, 'server_cache_service_id');
    }

    public function backupConfiguration(): BelongsTo
    {
        return $this->belongsTo(BackupConfiguration::class);
    }

    public function serverCronJob(): BelongsTo
    {
        return $this->belongsTo(ServerCronJob::class);
    }
}
