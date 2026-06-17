<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      One recorded scheduler run's captured output. Rolling history, count-capped
 *                      per heartbeat (pruned inline on write). See [[project_schedule_mirrors_workers]].
 * @property int $duration_ms
 * @property int $exit_code
 * @property ?Carbon $ran_at
 * @property ?string $server_scheduler_heartbeat_id
 * @property string $stderr_excerpt
 * @property string $stdout_excerpt
 * @property string $trigger
 * @property-read ?ServerSchedulerHeartbeat $heartbeat
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SchedulerTickOutput extends Model
{
    use HasUlids;

    public const TRIGGER_CRON = 'cron';

    public const TRIGGER_MANUAL = 'manual';

    /** Keep newest N rows per scheduler; older ones are pruned on write. */
    public const RETAIN_PER_HEARTBEAT = 50;

    /** Per-stream byte cap (matches the wrapper's 16KB excerpt cap). */
    public const STREAM_CAP_BYTES = 16384;

    protected $fillable = [
        'server_scheduler_heartbeat_id',
        'trigger',
        'exit_code',
        'duration_ms',
        'stdout_excerpt',
        'stderr_excerpt',
        'ran_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'ran_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServerSchedulerHeartbeat, $this> */
    public function heartbeat(): BelongsTo
    {
        return $this->belongsTo(ServerSchedulerHeartbeat::class, 'server_scheduler_heartbeat_id');
    }

    /** Truncate a stream to the byte cap, keeping the tail (most recent output). */
    public static function capStream(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (strlen($value) <= self::STREAM_CAP_BYTES) {
            return $value;
        }

        return "…(truncated)\n".substr($value, -self::STREAM_CAP_BYTES);
    }

    /**
     * Record a run and prune the heartbeat's history to RETAIN_PER_HEARTBEAT.
     */
    public static function record(
        string $heartbeatId,
        string $trigger,
        ?int $exitCode,
        ?string $stdout,
        ?string $stderr,
        ?\DateTimeInterface $ranAt = null,
        ?int $durationMs = null,
    ): self {
        $row = self::query()->create([
            'server_scheduler_heartbeat_id' => $heartbeatId,
            'trigger' => $trigger === self::TRIGGER_MANUAL ? self::TRIGGER_MANUAL : self::TRIGGER_CRON,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'stdout_excerpt' => self::capStream($stdout),
            'stderr_excerpt' => self::capStream($stderr),
            'ran_at' => $ranAt ?? now(),
        ]);

        // Prune inline: delete everything beyond the newest N for this scheduler.
        $keepIds = self::query()
            ->where('server_scheduler_heartbeat_id', $heartbeatId)
            ->orderByDesc('ran_at')
            ->limit(self::RETAIN_PER_HEARTBEAT)
            ->pluck('id');

        self::query()
            ->where('server_scheduler_heartbeat_id', $heartbeatId)
            ->whereNotIn('id', $keepIds)
            ->delete();

        return $row;
    }
}
