<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteWebserverConfigRevision extends Model
{
    use HasUlids;

    protected $fillable = [
        'site_webserver_config_profile_id',
        'user_id',
        'summary',
        'snapshot',
        'checksum',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SiteWebserverConfigProfile::class, 'site_webserver_config_profile_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
