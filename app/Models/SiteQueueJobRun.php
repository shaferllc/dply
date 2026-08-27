<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One job that actually ran, reported by the in-app agent.
 *
 * @property string $site_id
 * @property ?string $job_id
 * @property string $name
 * @property ?string $queue
 * @property ?string $connection
 * @property string $status
 * @property ?int $duration_ms
 * @property ?int $attempts
 * @property ?string $exception
 * @property ?string $message
 * @property Carbon $ran_at
 */
class SiteQueueJobRun extends Model
{
    use HasUlids;

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    /** In-app agent on the site's own server. */
    public const SOURCE_AGENT = 'agent';

    /** A managed worker server running the same app. */
    public const SOURCE_POOL = 'pool';

    /** dply's own round-trip probe — a job it dispatched to prove the pipe works. */
    public const SOURCE_CANARY = 'canary';

    /** One of the app's own job classes, dispatched from the Job classes tab. */
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'site_id', 'job_id', 'name', 'queue', 'connection',
        'status', 'source', 'worker_pool_id', 'duration_ms', 'attempts', 'exception', 'message', 'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'attempts' => 'integer',
            'ran_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
