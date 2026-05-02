<?php

namespace App\Models;

use Database\Factories\SiteProcessFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteProcess extends Model
{
    /** @use HasFactory<SiteProcessFactory> */
    use HasFactory, HasUlids;

    public const TYPE_WEB = 'web';

    public const TYPE_WORKER = 'worker';

    public const TYPE_SCHEDULER = 'scheduler';

    public const TYPE_CUSTOM = 'custom';

    protected $fillable = [
        'site_id',
        'type',
        'name',
        'command',
        'scale',
        'env_vars',
        'working_directory',
        'user',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'env_vars' => 'encrypted:array',
            'is_active' => 'boolean',
            'scale' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
