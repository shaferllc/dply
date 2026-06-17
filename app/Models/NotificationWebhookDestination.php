<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $driver
 * @property bool $enabled
 * @property array<string, mixed> $events
 * @property string $name
 * @property ?string $organization_id
 * @property ?string $site_id
 * @property string $webhook_url
 * @property-read ?Organization $organization
 * @property-read ?Site $site
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
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
