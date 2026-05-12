<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-server / per-org override for one webserver-health alert threshold.
 * Resolution precedence + table semantics documented in the migration.
 */
class WebserverHealthThreshold extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'server_id',
        'engine',
        'metric',
        'comparator',
        'value',
        'severity',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
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
}
