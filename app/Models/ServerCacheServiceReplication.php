<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * Edge connecting a master {@see ServerCacheService} to a replica
 * {@see ServerCacheService}. Created by the add-replica wizard; kept fresh by
 * {@see App\Console\Commands\PollCacheServiceReplicationCommand}.
 */
class ServerCacheServiceReplication extends Model
{
    use HasUlids;

    public const STATUS_CONFIGURING = 'configuring';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ERROR = 'error';

    public const STATUS_TEARDOWN = 'teardown';

    protected $table = 'server_cache_service_replications';

    protected $fillable = [
        'master_cache_service_id',
        'replica_cache_service_id',
        'status',
        'last_link_status',
        'last_observed_offset',
        'last_polled_at',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_observed_offset' => 'integer',
            'last_polled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServerCacheService, $this> */
    public function masterCacheService(): BelongsTo {
        return $this->belongsTo(ServerCacheService::class, 'master_cache_service_id');
    }

    /** @return BelongsTo<ServerCacheService, $this> */
    public function replicaCacheService(): BelongsTo {
        return $this->belongsTo(ServerCacheService::class, 'replica_cache_service_id');
    }
}
