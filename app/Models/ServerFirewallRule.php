<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'tags' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
