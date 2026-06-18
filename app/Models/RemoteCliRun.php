<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\RemoteCli\Services\Kind;
use App\Modules\RemoteCli\Services\RemoteCli;
use App\Modules\RemoteCli\Services\RiskLevel;
use Database\Factories\RemoteCliRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Persistent record of a single wp-cli or php artisan invocation.
 * Created when the operator (or a system pipeline) invokes a command
 * via the {@see RemoteCli} service. Sync runs
 * complete in-process before insert returns; async runs are inserted
 * in 'queued' state and updated by a queue worker.
 *
 * @property int $id
 * @property string $site_id
 * @property Kind $kind
 * @property string $command
 * @property array<int, string>|null $args
 * @property RiskLevel $risk
 * @property string $mode 'sync' | 'async'
 * @property string $status 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'
 * @property int|null $exit_code
 * @property string|null $stdout
 * @property string|null $stderr
 * @property string|null $queued_by_user_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $cancelled_at
 * @property string $mode
 * @property string $status
 * @property-read ?Site $site
 * @property-read ?User $queuedByUser
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class RemoteCliRun extends Model
{
    /** @use HasFactory<RemoteCliRunFactory> */
    use HasFactory;

    protected $table = 'remote_cli_runs';

    protected $fillable = [
        'site_id',
        'kind',
        'command',
        'args',
        'risk',
        'mode',
        'status',
        'exit_code',
        'stdout',
        'stderr',
        'queued_by_user_id',
        'started_at',
        'finished_at',
        'cancelled_at',
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const MODE_SYNC = 'sync';

    public const MODE_ASYNC = 'async';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => Kind::class,
            'args' => 'array',
            'risk' => RiskLevel::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function queuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'queued_by_user_id');
    }

    public function isTerminal(): bool
    {
        return in_array(
            $this->status,
            [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED],
            true,
        );
    }

    protected static function newFactory(): RemoteCliRunFactory
    {
        return RemoteCliRunFactory::new();
    }
}
