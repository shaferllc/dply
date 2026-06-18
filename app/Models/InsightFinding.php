<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property ?Carbon $acknowledged_at
 * @property ?string $acknowledged_by_user_id
 * @property string $body
 * @property array<string, mixed> $correlation
 * @property string $dedupe_hash
 * @property ?Carbon $detected_at
 * @property ?Carbon $ignored_at
 * @property ?string $ignored_by_user_id
 * @property string $insight_key
 * @property string $kind
 * @property array<string, mixed> $meta
 * @property ?Carbon $resolved_at
 * @property ?string $server_id
 * @property string $severity
 * @property ?string $site_id
 * @property string $status
 * @property ?string $team_id
 * @property string $title
 * @property-read ?Server $server
 * @property-read ?Site $site
 * @property-read ?Team $team
 * @property-read ?User $acknowledgedBy
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class InsightFinding extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public const KIND_PROBLEM = 'problem';

    public const KIND_SUGGESTION = 'suggestion';

    protected $fillable = [
        'server_id',
        'site_id',
        'team_id',
        'insight_key',
        'kind',
        'dedupe_hash',
        'status',
        'severity',
        'title',
        'body',
        'meta',
        'correlation',
        'detected_at',
        'resolved_at',
        'acknowledged_at',
        'acknowledged_by_user_id',
        'ignored_at',
        'ignored_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'correlation' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'ignored_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isIgnored(): bool
    {
        return $this->status === self::STATUS_IGNORED;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    /**
     * Higher number = more important. Used by the Insights banner and
     * findings list to push critical entries to the top.
     */
    public function severityRank(): int
    {
        return match ($this->severity) {
            self::SEVERITY_CRITICAL => 30,
            self::SEVERITY_WARNING => 20,
            self::SEVERITY_INFO => 10,
            default => 0,
        };
    }
}
