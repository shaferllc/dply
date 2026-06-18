<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $digest_bucket
 * @property string $event_kind
 * @property string $line
 * @property ?string $notification_channel_id
 * @property ?string $organization_id
 * @property ?string $server_id
 * @property string $unit
 * @property-read ?NotificationChannel $channel
 * @property-read ?Server $server
 * @property-read ?Organization $organization
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerSystemdNotificationDigestLine extends Model
{
    protected $table = 'server_systemd_notification_digest_lines';

    protected $fillable = [
        'notification_channel_id',
        'server_id',
        'organization_id',
        'digest_bucket',
        'unit',
        'event_kind',
        'line',
    ];

    /** @return BelongsTo<NotificationChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
