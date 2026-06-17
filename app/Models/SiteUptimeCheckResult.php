<?php

namespace App\Models;

use App\Services\Status\MonitorOperationalState;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * One recorded uptime check. Append-only history behind a monitor's last_*
 * snapshot; powers uptime %, latency trends and incident stitching. `state`
 * mirrors {@see MonitorOperationalState} values.
 */
class SiteUptimeCheckResult extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'site_uptime_monitor_id',
        'checked_at',
        'state',
        'http_status',
        'latency_ms',
        'error',
        'probe_worker',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SiteUptimeMonitor, $this> */
    public function monitor(): BelongsTo {
        return $this->belongsTo(SiteUptimeMonitor::class, 'site_uptime_monitor_id');
    }
}
