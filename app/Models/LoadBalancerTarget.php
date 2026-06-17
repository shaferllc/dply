<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?Carbon $drained_at
 * @property ?string $load_balancer_id
 * @property ?string $provider_server_id
 * @property ?string $server_id
 * @property string $status
 * @property int $weight
 * @property-read ?LoadBalancer $loadBalancer
 * @property-read ?Server $server
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
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
    public function loadBalancer(): BelongsTo
    {
        return $this->belongsTo(LoadBalancer::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
