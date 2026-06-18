<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Edge\EdgeArtifactPublisher;
use App\Services\Edge\EdgeDeliveryContextResolver;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property array<string, mixed> $aliases
 * @property ?string $build_log_path
 * @property int $cf_kv_version
 * @property ?Carbon $failed_at
 * @property ?string $failure_reason
 * @property ?string $git_branch
 * @property ?string $git_commit
 * @property array<string, mixed> $meta
 * @property ?string $organization_id
 * @property ?Carbon $pruned_at
 * @property ?Carbon $published_at
 * @property array<string, mixed> $repo_config
 * @property ?string $site_id
 * @property string $status
 * @property ?string $storage_prefix
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?Site $site
 * @property-read ?Organization $organization
 */
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

    /** @return array<string, string> */
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
        return array_values(array_filter(array_map(
            static fn ($value): string => is_string($value) ? strtolower(trim($value)) : '',
            $this->aliases,
        ), static fn (string $value): bool => $value !== ''));
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    /**
     * Live-tail helper for the in-flight build log. While the build is
     * still running the log is on the queue worker's local filesystem
     * (path stashed in meta.local_build_log_path); after publish, the
     * runner deletes the local copy and persists to the remote disk
     * — at which point this method just returns an empty body so the
     * Livewire poller stops appending.
     *
     * Returns the new bytes since `$offset` (capped at `$maxBytes`) plus
     * the new offset the caller should use on the next poll.
     *
     * @return array{body: string, offset: int, exists: bool}
     */
    public function readLocalBuildLogSince(int $offset, int $maxBytes = 32_000): array
    {
        $path = $this->meta['local_build_log_path'] ?? null;
        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            return ['body' => '', 'offset' => $offset, 'exists' => false];
        }

        $size = @filesize($path);
        if ($size === false || $size <= $offset) {
            return ['body' => '', 'offset' => $offset, 'exists' => true];
        }

        $bytesAvailable = $size - $offset;
        $bytesToRead = (int) min($bytesAvailable, max(1, $maxBytes));
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ['body' => '', 'offset' => $offset, 'exists' => true];
        }
        try {
            if (@fseek($handle, $offset) !== 0) {
                return ['body' => '', 'offset' => $offset, 'exists' => true];
            }
            $body = (string) @fread($handle, $bytesToRead);
        } finally {
            @fclose($handle);
        }

        return [
            'body' => $body,
            'offset' => $offset + strlen($body),
            'exists' => true,
        ];
    }

    public function readBuildLog(?Site $site = null): ?string
    {
        if (blank($this->build_log_path)) {
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
