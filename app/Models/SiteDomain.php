<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDomain extends Model
{
    use HasUlids;

    protected $table = 'site_domains';

    protected $fillable = [
        'site_id',
        'hostname',
        'is_primary',
        'www_redirect',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'www_redirect' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
