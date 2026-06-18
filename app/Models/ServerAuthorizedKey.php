<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $managed_key_id
 * @property string $managed_key_type
 * @property string $name
 * @property string $public_key
 * @property Carbon $review_after
 * @property ?string $server_id
 * @property ?Carbon $synced_at
 * @property string $target_linux_user
 * @property-read ?Server $server
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerAuthorizedKey extends Model
{
    use HasUlids;

    protected $fillable = [
        'server_id',
        'target_linux_user',
        'managed_key_type',
        'managed_key_id',
        'name',
        'public_key',
        'synced_at',
        'review_after',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
            'review_after' => 'date',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return MorphTo<Model, $this> */
    public function managedKey(): MorphTo
    {
        return $this->morphTo();
    }
}
