<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationSubscription extends Model
{
    use HasUlids;

    protected $fillable = [
        'notification_channel_id',
        'subscribable_type',
        'subscribable_id',
        'event_key',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }
}
