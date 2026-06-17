<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */

class EdgePerformanceHourly extends Model
{
    use HasUlids;

    protected $table = 'edge_performance_hourly';

    protected $fillable = [
        'organization_id',
        'site_id',
        'hour_start',
        'requests',
        'bytes_egress',
        'duration_ms_total',
        'duration_ms_p95',
        'status_2xx',
        'status_4xx',
        'status_5xx',
        'cache_hits',
        'source',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hour_start' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo {
        return $this->belongsTo(Site::class);
    }
}
