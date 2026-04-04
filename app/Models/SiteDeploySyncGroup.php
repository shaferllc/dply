<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SiteDeploySyncGroup extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'name',
        'leader_site_id',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'leader_site_id');
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_deploy_sync_group_sites', 'site_deploy_sync_group_id', 'site_id')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }
}
