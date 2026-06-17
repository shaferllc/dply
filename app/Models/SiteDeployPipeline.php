<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 */

class SiteDeployPipeline extends Model
{
    use HasUlids;

    protected $fillable = [
        'site_id',
        'name',
        'slug',
        'description',
        'deploy_branches',
        'clone_script',
        'activate_script',
        'is_default',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'deploy_branches' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<SiteDeployStep, $this> */
    public function steps(): HasMany {
        return $this->hasMany(SiteDeployStep::class, 'pipeline_id')->orderBy('sort_order');
    }

    /** @return HasMany<SiteDeployHook, $this> */
    public function hooks(): HasMany {
        return $this->hasMany(SiteDeployHook::class, 'pipeline_id')->orderBy('sort_order');
    }

    public function isActiveFor(Site $site): bool
    {
        return (string) $site->active_deploy_pipeline_id === (string) $this->id;
    }
}
