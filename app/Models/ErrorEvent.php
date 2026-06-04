<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Errors\ErrorRetryRegistry;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row in the dedicated error stream surfaced on the site/server "Errors"
 * views. Written by {@see \App\Support\Errors\ErrorEventRecorder} from failed
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
            ? app(\App\Services\Remediations\RemediationCatalog::class)->find((string) $this->remediation_code)
            : null;
    }
}
