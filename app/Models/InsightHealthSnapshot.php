<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property ?Carbon $captured_at
 * @property array<string, mixed> $counts
 * @property string $score
 * @property ?string $server_id
 * @property-read ?Server $server
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class InsightHealthSnapshot extends Model
{
    protected $fillable = [
        'server_id',
        'score',
        'counts',
        'captured_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
