<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property ?string $server_id
 * @property ?Carbon $occurred_at
 * @property string $kind
 * @property string $unit
 * @property ?string $label
 * @property string $detail
 * @property-read ?Server $server
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerSystemdServiceAuditEvent extends Model
{
    protected $fillable = [
        'server_id',
        'occurred_at',
        'kind',
        'unit',
        'label',
        'detail',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
