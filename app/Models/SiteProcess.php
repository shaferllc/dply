<?php

namespace App\Models;

use Database\Factories\SiteProcessFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $command
 * @property array<string, mixed> $env_vars
 * @property bool $is_active
 * @property bool $managed_by_manifest
 * @property string $name
 * @property int $scale
 * @property ?string $site_id
 * @property string $type
 * @property string $user
 * @property string $working_directory
 * @property-read ?Site $site
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
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
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
