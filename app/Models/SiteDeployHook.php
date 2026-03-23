<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDeployHook extends Model
{
    public const PHASE_BEFORE_CLONE = 'before_clone';

    public const PHASE_AFTER_CLONE = 'after_clone';

    public const PHASE_AFTER_ACTIVATE = 'after_activate';

    protected $fillable = [
        'site_id',
        'sort_order',
        'phase',
        'script',
        'timeout_seconds',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
