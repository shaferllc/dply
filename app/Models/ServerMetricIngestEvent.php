<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Row written when a BYO control plane POSTs a metric snapshot to the ingest API (central stats).
 *
 * @property int $id
 * @property int|null $source_snapshot_id
 * @property string $organization_id
 * @property string $server_id
 * @property string|null $server_name
 * @property Carbon $captured_at
 * @property array<string, mixed> $metrics
 */
class ServerMetricIngestEvent extends Model
{
    protected $fillable = [
        'source_snapshot_id',
        'organization_id',
        'server_id',
        'server_name',
        'captured_at',
        'metrics',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'metrics' => 'array',
        ];
    }
}
