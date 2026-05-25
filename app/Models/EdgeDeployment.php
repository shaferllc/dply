<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Edge\EdgeArtifactPublisher;
use App\Services\Edge\EdgeDeliveryContextResolver;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdgeDeployment extends Model
{
    use HasUlids;

    public const STATUS_BUILDING = 'building';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_LIVE = 'live';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'site_id',
        'organization_id',
        'status',
        'git_commit',
        'git_branch',
        'storage_prefix',
        'build_log_path',
        'cf_kv_version',
        'aliases',
        'repo_config',
        'published_at',
        'failed_at',
        'failure_reason',
        'pruned_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'aliases' => 'array',
            'repo_config' => 'array',
            'published_at' => 'datetime',
            'failed_at' => 'datetime',
            'pruned_at' => 'datetime',
            'cf_kv_version' => 'integer',
        ];
    }

    /**
     * Stable per-deployment alias hostnames. Each one resolves to this
     * deployment via the KV host map so operators can deep-link any
     * historical build, even when production has moved on.
     *
     * @return list<string>
     */
    public function aliasHostnames(): array
    {
        $aliases = is_array($this->aliases) ? $this->aliases : [];

        return array_values(array_filter(array_map(
            static fn ($value): string => is_string($value) ? strtolower(trim($value)) : '',
            $aliases,
        ), static fn (string $value): bool => $value !== ''));
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function readBuildLog(?Site $site = null): ?string
    {
        if (! is_string($this->build_log_path) || $this->build_log_path === '') {
            return null;
        }

        $site ??= $this->site;
        if ($site === null) {
            return null;
        }

        try {
            $context = app(EdgeDeliveryContextResolver::class)->forSite($site);

            return app(EdgeArtifactPublisher::class)->readFile($this->build_log_path, $context->diskName);
        } catch (\Throwable) {
            return app(EdgeArtifactPublisher::class)->readFile(
                $this->build_log_path,
                (string) config('edge.disk.name', 'edge_r2'),
            );
        }
    }
}
