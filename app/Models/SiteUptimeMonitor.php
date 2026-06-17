<?php

namespace App\Models;

use Database\Factories\SiteUptimeMonitorFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $check_type
 * @property array<string, mixed> $config
 * @property string $label
 * @property ?Carbon $last_checked_at
 * @property string $last_error
 * @property string $last_http_status
 * @property string $last_latency_ms
 * @property array<string, mixed> $last_meta
 * @property bool $last_ok
 * @property ?string $last_state
 * @property ?string $path
 * @property string $probe_region
 * @property string $probe_worker
 * @property ?string $site_id
 * @property string $sort_order
 * @property-read ?Site $site
 * @property-read Collection<int, StatusPageMonitor> $statusPageMonitors
 * @property-read Collection<int, SiteUptimeCheckResult> $checkResults
 * @property-read Collection<int, SiteUptimeIncident> $incidents
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class SiteUptimeMonitor extends Model
{
    /** @use HasFactory<SiteUptimeMonitorFactory> */
    use HasFactory, HasUlids;

    /** A plain HTTP GET (with optional keyword / status / latency assertions). */
    public const CHECK_HTTP = 'http';

    /** A TLS handshake that flags certs expiring within the warn window. */
    public const CHECK_SSL = 'ssl';

    public const MATCH_CONTAIN = 'must_contain';

    public const MATCH_NOT_CONTAIN = 'must_not_contain';

    protected $fillable = [
        'site_id',
        'label',
        'check_type',
        'path',
        'config',
        'probe_region',
        'probe_worker',
        'sort_order',
        'last_checked_at',
        'last_ok',
        'last_state',
        'last_http_status',
        'last_latency_ms',
        'last_error',
        'last_meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
            'last_ok' => 'boolean',
            'config' => 'array',
            'last_meta' => 'array',
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

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return MorphMany<StatusPageMonitor, $this> */
    public function statusPageMonitors(): MorphMany
    {
        return $this->morphMany(StatusPageMonitor::class, 'monitorable');
    }

    /** @return HasMany<SiteUptimeCheckResult, $this> */
    public function checkResults(): HasMany
    {
        return $this->hasMany(SiteUptimeCheckResult::class);
    }

    /** @return HasMany<SiteUptimeIncident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(SiteUptimeIncident::class);
    }

    /** The currently-open (unresolved) incident, if any. *
     * @return HasOne<SiteUptimeIncident, $this>
     */
    /** @return HasOne<SiteUptimeIncident, $this> */
    public function ongoingIncident(): HasOne
    {
        return $this->hasOne(SiteUptimeIncident::class)->whereNull('resolved_at')->latestOfMany('started_at');
    }

    public function isSslCheck(): bool
    {
        return ($this->check_type ?? self::CHECK_HTTP) === self::CHECK_SSL;
    }

    /** Keyword body assertion, or null when none is configured. */
    public function keywordAssertion(): ?string
    {
        $keyword = $this->config['keyword'] ?? null;
        $keyword = is_string($keyword) ? trim($keyword) : '';

        return $keyword === '' ? null : $keyword;
    }

    public function keywordMatchMode(): string
    {
        $mode = $this->config['match_mode'] ?? null;

        return $mode === self::MATCH_NOT_CONTAIN ? self::MATCH_NOT_CONTAIN : self::MATCH_CONTAIN;
    }

    /** Exact HTTP status the check must return, or null to accept any 2xx. */
    public function expectedStatus(): ?int
    {
        $status = $this->config['expected_status'] ?? null;

        return is_numeric($status) && (int) $status > 0 ? (int) $status : null;
    }

    /** Latency ceiling (ms) above which an up monitor reads DEGRADED, or null when off. */
    public function responseThresholdMs(): ?int
    {
        $threshold = $this->config['response_threshold_ms'] ?? null;

        return is_numeric($threshold) && (int) $threshold > 0 ? (int) $threshold : null;
    }

    /** Days before cert expiry to warn (SSL checks); falls back to config default. */
    public function sslWarnDays(): int
    {
        $days = $this->config['ssl_warn_days'] ?? null;
        if (is_numeric($days) && (int) $days > 0) {
            return (int) $days;
        }

        return max(1, (int) config('site_uptime.ssl_warn_days', 14));
    }

    /**
     * Minutes the scheduler waits between probes for this monitor. A monitor
     * that is currently down backs off to the slower down-interval until it
     * recovers; healthy / unknown / never-checked monitors run on the base
     * cadence. Both the dispatcher (which gate-keeps probes) and the staleness
     * resolver read this, so a backed-off down monitor isn't mistaken for
     * "unknown" between its slower checks.
     */
    public function effectiveCheckIntervalMinutes(): int
    {
        // SSL is a slow-moving fact — probe daily and stay daily even when
        // failing (a cert doesn't un-expire in 15 minutes).
        if ($this->isSslCheck()) {
            return max(60, (int) config('site_uptime.ssl_check_interval_minutes', 1440));
        }

        $base = max(1, (int) config('site_uptime.check_interval_minutes', 5));

        if ($this->last_ok === false) {
            return max($base, (int) config('site_uptime.down_check_interval_minutes', 15));
        }

        return $base;
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
