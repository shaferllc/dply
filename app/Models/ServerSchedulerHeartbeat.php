<?php

namespace App\Models;

use App\Models\Concerns\DescribesCronExpression;
use Database\Factories\ServerSchedulerHeartbeatFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per (server, site, scheduler_kind) — the live state of a framework
 * scheduler's heartbeat as last reported by the metrics agent.
 *
 * Created/updated by the metrics ingest endpoint on each agent push; never
 * written from the page render path. The Schedule page reads from here for
 * status chips; Insights runners query the table to detect missed ticks.
 *
 * Per-task run history is stored separately in `scheduler_task_runs` (added
 * in milestone 3) and joins back via `heartbeat_id`.
 */
class ServerSchedulerHeartbeat extends Model
{
    /** @use HasFactory<ServerSchedulerHeartbeatFactory> */
    use DescribesCronExpression, HasFactory, HasUlids;

    public const KIND_LARAVEL = 'laravel';

    public const KIND_RAILS = 'rails';

    public const KIND_GENERIC = 'generic';

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::KIND_LARAVEL, self::KIND_RAILS, self::KIND_GENERIC];
    }

    protected $fillable = [
        'server_id',
        'site_id',
        'scheduler_kind',
        'cron_expression',
        'last_tick_at',
        'last_exit_code',
        'last_duration_ms',
        'last_memory_peak_kb',
        'consecutive_misses',
        'first_seen_at',
        'circuit_open',
        'output_capture_enabled',
    ];

    protected function casts(): array
    {
        return [
            'last_tick_at' => 'datetime',
            'last_exit_code' => 'integer',
            'last_duration_ms' => 'integer',
            'last_memory_peak_kb' => 'integer',
            'consecutive_misses' => 'integer',
            'first_seen_at' => 'datetime',
            'circuit_open' => 'boolean',
            'output_capture_enabled' => 'boolean',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<SchedulerTickOutput, $this> */
    public function tickOutputs(): HasMany
    {
        return $this->hasMany(SchedulerTickOutput::class)->latest('ran_at');
    }
}
