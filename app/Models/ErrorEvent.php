<?php

declare(strict_types=1);

namespace App\Models;

use App\Livewire\Servers\WorkspaceErrors;
use App\Livewire\Sites\Errors;
use App\Services\Remediations\RemediationCatalog;
use App\Support\Errors\ErrorEventRecorder;
use App\Support\Errors\ErrorRetryRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row in the dedicated error stream surfaced on the site/server "Errors"
 * views. Written by {@see ErrorEventRecorder} from failed
 * ConsoleActions and SiteDeployments. Append-only; triage is a shared
 * {@see $dismissed_at}.
 */
class ErrorEvent extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'server_id',
        'site_id',
        'source_type',
        'source_id',
        'category',
        'remediation_code',
        'reference',
        'title',
        'detail',
        'link_url',
        'occurred_at',
        'dismissed_at',
        'dismissed_by',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function dismisser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    public function scopeForServer(Builder $query, string $serverId): Builder
    {
        return $query->where('server_id', $serverId);
    }

    public function scopeForSite(Builder $query, string $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeUndismissed(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    /**
     * Request-scoped memo of the undismissed error count per server. The server
     * workspace nav badge ({@see server-workspace-shell}) and the Errors stream
     * both want this count; sharing it here keeps the same `count(*)` from
     * running twice on a page render. Primed by the stream's paginator when it's
     * unfiltered (see {@see WorkspaceErrors::shareStreamTotal});
     * otherwise computed on first read. Keyed per server, reset each request.
     *
     * @var array<string, int>
     */
    protected static array $undismissedServerCountMemo = [];

    public static function undismissedCountForServer(string $serverId): int
    {
        return static::$undismissedServerCountMemo[$serverId] ??= static::query()
            ->where('server_id', $serverId)
            ->whereNull('dismissed_at')
            ->count();
    }

    /** Seed the memo from a count computed elsewhere (e.g. the stream paginator). */
    public static function primeUndismissedCountForServer(string $serverId, int $count): void
    {
        static::$undismissedServerCountMemo[$serverId] = $count;
    }

    /**
     * Request-scoped memo of the undismissed error count per site — the site
     * mirror of {@see $undismissedServerCountMemo}. The site settings sidebar
     * "Errors" badge and the Errors stream both want this; sharing it keeps the
     * same `count(*)` from running twice on a render. Primed by the stream's
     * paginator when unfiltered (see {@see Errors::shareStreamTotal}).
     *
     * @var array<string, int>
     */
    protected static array $undismissedSiteCountMemo = [];

    public static function undismissedCountForSite(string $siteId): int
    {
        return static::$undismissedSiteCountMemo[$siteId] ??= static::query()
            ->where('site_id', $siteId)
            ->whereNull('dismissed_at')
            ->count();
    }

    /** Seed the memo from a count computed elsewhere (e.g. the stream paginator). */
    public static function primeUndismissedCountForSite(string $siteId, int $count): void
    {
        static::$undismissedSiteCountMemo[$siteId] = $count;
    }

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    /** Whether the feed can re-run this error's origin in place. */
    public function isRetryable(): bool
    {
        return app(ErrorRetryRegistry::class)->isRetryable((string) $this->category);
    }

    /**
     * The recognized remediation for this error, if any (matched at capture time).
     *
     * @return array<string, mixed>|null
     */
    public function remediation(): ?array
    {
        return $this->remediation_code
            ? app(RemediationCatalog::class)->find((string) $this->remediation_code)
            : null;
    }
}
