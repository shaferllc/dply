<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    public const KIND_BYO_SITE = 'byo_site';

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'slug',
        'kind',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): HasOne
    {
        return $this->hasOne(Site::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(SiteDeployment::class, 'project_id')->orderByDesc('id');
    }
}
