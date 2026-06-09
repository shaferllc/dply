<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A continuous span where a monitor was not operational. Opened on the
 * down/degraded transition and closed on recovery (resolved_at set). An
 * `outage` counts against uptime %; a `degraded` shows on the timeline but
 * still counts as up. SSL expiry warnings are not incidents.
 */
class SiteUptimeIncident extends Model
{
    use HasUlids;

    public const SEVERITY_DEGRADED = 'degraded';

    public const SEVERITY_OUTAGE = 'outage';

    protected $fillable = [
        'site_uptime_monitor_id',
        'site_id',
        'severity',
        'cause',
        'started_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(SiteUptimeMonitor::class, 'site_uptime_monitor_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isOngoing(): bool
    {
        return $this->resolved_at === null;
    }
}
