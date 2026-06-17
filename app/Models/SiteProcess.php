<?php

namespace App\Models;

use Database\Factories\SiteProcessFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */

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
        'managed_by_manifest',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'env_vars' => 'encrypted:array',
            'is_active' => 'boolean',
            'scale' => 'integer',
            'managed_by_manifest' => 'boolean',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo {
        return $this->belongsTo(Site::class);
    }
}
