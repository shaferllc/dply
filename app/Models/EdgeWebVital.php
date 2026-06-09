<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdgeWebVital extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'site_id',
        'edge_deployment_id',
        'hostname',
        'path',
        'lcp_ms',
        'cls',
        'inp_ms',
        'fcp_ms',
        'ttfb_ms',
        'country',
        'source',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'cls' => 'float',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
