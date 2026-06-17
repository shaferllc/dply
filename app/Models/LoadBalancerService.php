<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $destination_port
 * @property bool $health_check_enabled
 * @property string $health_check_path
 * @property int $health_check_port
 * @property string $health_check_protocol
 * @property int $listen_port
 * @property ?string $load_balancer_id
 * @property array<string, mixed> $meta
 * @property string $protocol
 * @property bool $sticky_sessions
 * @property-read ?LoadBalancer $loadBalancer
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class LoadBalancerService extends Model
{
    use HasUlids;

    protected $fillable = [
        'load_balancer_id',
        'protocol',
        'listen_port',
        'destination_port',
        'sticky_sessions',
        'health_check_enabled',
        'health_check_protocol',
        'health_check_port',
        'health_check_path',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'listen_port' => 'integer',
            'destination_port' => 'integer',
            'sticky_sessions' => 'boolean',
            'health_check_enabled' => 'boolean',
            'health_check_port' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<LoadBalancer, $this> */
    public function loadBalancer(): BelongsTo
    {
        return $this->belongsTo(LoadBalancer::class);
    }
}
