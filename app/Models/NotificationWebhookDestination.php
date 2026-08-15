<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DEPRECATED (2026-08-14) — superseded by NotificationChannel + NotificationSubscription.
 *
 * This was a second, weaker fan-out off the same NotificationEvent: 3 drivers vs
 * 10, 9 hard-coded events vs the full config/notification_events.php catalogue,
 * pasted URLs only, no test-send, and a Teams driver still posting a MessageCard
 * to an Office 365 connector Microsoft retired in May 2026.
 *
 * All UI and the routing call are gone; rows were copied across by
 * 2026_08_14_120000_migrate_webhook_destinations_to_notification_channels.
 * The class and table are kept only so that migration stays recoverable by hand —
 * drop both once you are satisfied nothing was lost.
 */
/**
 * @property string $id
 * @property string $driver
 * @property bool $enabled
 * @property ?array<string, mixed> $events
 * @property string $name
 * @property ?string $organization_id
 * @property ?string $site_id
 * @property string $webhook_url
 * @property-read ?Organization $organization
 * @property-read ?Site $site
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class NotificationWebhookDestination extends Model
{
    use HasUlids;

    protected $table = 'notification_webhook_destinations';

    public const DRIVER_SLACK = 'slack';

    public const DRIVER_DISCORD = 'discord';

    public const DRIVER_TEAMS = 'teams';

    protected $fillable = [
        'organization_id',
        'site_id',
        'name',
        'driver',
        'webhook_url',
        'events',
        'enabled',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'webhook_url' => 'encrypted',
            'events' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function wantsEvent(string $event): bool
    {
        $events = $this->events;
        if ($events === []) {
            return true;
        }

        return in_array($event, $events, true);
    }
}
