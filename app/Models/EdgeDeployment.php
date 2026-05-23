<?php

declare(strict_types=1);

namespace App\Models;

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
        'published_at',
        'failed_at',
        'failure_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'published_at' => 'datetime',
            'failed_at' => 'datetime',
            'cf_kv_version' => 'integer',
        ];
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
}
