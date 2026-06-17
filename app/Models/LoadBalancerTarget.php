<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */

class LoadBalancerTarget extends Model
{
    use HasUlids;

    protected $fillable = [
        'load_balancer_id',
        'server_id',
        'provider_server_id',
        'status',
        'weight',
        'drained_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'drained_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LoadBalancer, $this> */
    public function loadBalancer(): BelongsTo {
        return $this->belongsTo(LoadBalancer::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }
}
