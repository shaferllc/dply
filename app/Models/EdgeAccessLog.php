<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdgeAccessLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'site_id',
        'edge_deployment_id',
        'hostname',
        'method',
        'path',
        'status_code',
        'duration_ms',
        'bytes_egress',
        'country',
        'cache_status',
        'referrer',
        'user_agent',
        'source',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
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
}
