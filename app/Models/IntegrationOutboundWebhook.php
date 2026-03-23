<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationOutboundWebhook extends Model
{
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

    protected function casts(): array
    {
        return [
            'webhook_url' => 'encrypted',
            'events' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function wantsEvent(string $event): bool
    {
        $events = $this->events;
        if (! is_array($events) || $events === []) {
            return true;
        }

        return in_array($event, $events, true);
    }
}
