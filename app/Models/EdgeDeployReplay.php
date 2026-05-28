<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function parentSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'parent_site_id');
    }

    public function previewSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'preview_site_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
