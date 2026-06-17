<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $notification_channel_id
 * @property string $subscribable_type
 * @property string $subscribable_id
 * @property string $event_key
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read NotificationChannel $channel
 * @property-read Model $subscribable
 */
class NotificationSubscription extends Model
{
    use HasUlids;

    protected $fillable = [
        'notification_channel_id',
        'subscribable_type',
        'subscribable_id',
        'event_key',
    ];

    /** @return BelongsTo<NotificationChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }
}
