<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One queue's depth at one moment, for one site.
 *
 * @property string $id
 * @property string $site_id
 * @property ?string $connection
 * @property string $queue
 * @property string $source
 * @property ?int $pending
 * @property ?int $delayed
 * @property ?int $reserved
 * @property ?int $failed_total
 * @property ?string $last_failure
 * @property ?int $oldest_pending_age_s
 * @property ?int $time_to_clear_s
 * @property ?int $worker_processes
 * @property Carbon $captured_at
 */
class SiteQueueSnapshot extends Model
{
    use HasUlids;

    public const SOURCE_HORIZON = 'horizon';

    public const SOURCE_ARTISAN = 'artisan';

    public const SOURCE_POOL = 'pool';

    protected $fillable = [
        'site_id',
        'connection',
        'queue',
        'source',
        'pending',
        'delayed',
        'reserved',
        'failed_total',
        'last_failure',
        'oldest_pending_age_s',
        'time_to_clear_s',
        'worker_processes',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'pending' => 'integer',
            'delayed' => 'integer',
            'reserved' => 'integer',
            'failed_total' => 'integer',
            'oldest_pending_age_s' => 'integer',
            'time_to_clear_s' => 'integer',
            'worker_processes' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
