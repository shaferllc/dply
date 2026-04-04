<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteWebserverConfigProfile extends Model
{
    use HasUlids;

    public const MODE_LAYERED = 'layered';

    public const MODE_FULL_OVERRIDE = 'full_override';

    protected $fillable = [
        'site_id',
        'webserver',
        'mode',
        'before_body',
        'main_snippet_body',
        'after_body',
        'full_override_body',
        'last_applied_effective_checksum',
        'last_applied_core_hash',
        'last_applied_at',
        'draft_saved_at',
    ];

    protected function casts(): array
    {
        return [
            'last_applied_at' => 'datetime',
            'draft_saved_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SiteWebserverConfigRevision::class);
    }

    public function isFullOverride(): bool
    {
        return $this->mode === self::MODE_FULL_OVERRIDE;
    }
}
