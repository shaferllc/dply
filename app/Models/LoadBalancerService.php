<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function loadBalancer(): BelongsTo
    {
        return $this->belongsTo(LoadBalancer::class);
    }
}
