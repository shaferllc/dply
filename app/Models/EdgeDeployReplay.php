<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */

class EdgeDeployReplay extends Model
{
    use HasUlids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'parent_site_id',
        'preview_site_id',
        'preview_deployment_id',
        'triggered_by_user_id',
        'status',
        'sample_limit',
        'window_minutes',
        'samples',
        'results',
        'summary',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'samples' => 'array',
            'results' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function parentSite(): BelongsTo {
        return $this->belongsTo(Site::class, 'parent_site_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function previewSite(): BelongsTo {
        return $this->belongsTo(Site::class, 'preview_site_id');
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
