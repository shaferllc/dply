<?php

namespace App\Models;

use Database\Factories\SiteUptimeMonitorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SiteUptimeMonitor extends Model
{
    /** @use HasFactory<SiteUptimeMonitorFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'site_id',
        'label',
        'path',
        'probe_region',
        'sort_order',
        'last_checked_at',
        'last_ok',
        'last_http_status',
        'last_latency_ms',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
            'last_ok' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (SiteUptimeMonitor $monitor): void {
            StatusPageMonitor::query()
                ->where('monitorable_type', self::class)
                ->where('monitorable_id', $monitor->id)
                ->delete();
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function statusPageMonitors(): MorphMany
    {
        return $this->morphMany(StatusPageMonitor::class, 'monitorable');
    }

    /**
     * Normalized path for URL building: "" or "/foo/bar".
     */
    public function normalizedPath(): string
    {
        $path = $this->path;
        if ($path === null || $path === '') {
            return '';
        }

        $path = '/'.ltrim($path, '/');

        return $path === '/' ? '' : $path;
    }
}
