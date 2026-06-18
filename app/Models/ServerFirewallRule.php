<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $action
 * @property string $app_profile
 * @property bool $enabled
 * @property string $iface
 * @property string $iface_direction
 * @property string $name
 * @property string $port
 * @property string $profile
 * @property string $protocol
 * @property string $runbook_url
 * @property ?string $server_id
 * @property ?string $site_id
 * @property string $sort_order
 * @property string $source
 * @property array<string, mixed> $tags
 * @property-read ?Server $server
 * @property-read ?Site $site
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerFirewallRule extends Model
{
    use HasUlids;

    protected $fillable = [
        'server_id',
        'site_id',
        'name',
        'profile',
        'app_profile',
        'tags',
        'runbook_url',
        'port',
        'protocol',
        'source',
        'iface',
        'iface_direction',
        'action',
        'enabled',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'tags' => 'array',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
