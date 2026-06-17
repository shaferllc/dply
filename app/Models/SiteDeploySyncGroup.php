<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 */

class SiteDeploySyncGroup extends Model
{
    use HasUlids;

    public const ROLLOUT_PARALLEL = 'parallel';

    public const ROLLOUT_SEQUENTIAL = 'sequential';

    protected $fillable = [
        'organization_id',
        'name',
        'leader_site_id',
        'rollout_mode',
    ];

    public function isSequential(): bool
    {
        return ($this->rollout_mode ?? self::ROLLOUT_PARALLEL) === self::ROLLOUT_SEQUENTIAL;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function leader(): BelongsTo {
        return $this->belongsTo(Site::class, 'leader_site_id');
    }

    /** @return BelongsToMany<Site, $this> */
    public function sites(): BelongsToMany {
        return $this->belongsToMany(Site::class, 'site_deploy_sync_group_sites', 'site_deploy_sync_group_id', 'site_id')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }
}
