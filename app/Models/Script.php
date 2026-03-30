<?php

namespace App\Models;

use Database\Factories\ScriptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Script extends Model
{
    public const SOURCE_USER_CREATED = 'user_created';

    public const SOURCE_MARKETPLACE = 'marketplace';

    /** @use HasFactory<ScriptFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'content',
        'run_as_user',
        'source',
        'marketplace_key',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sitesUsingAsDeploy(): HasMany
    {
        return $this->hasMany(Site::class, 'deploy_script_id');
    }

    public function isDefaultForOrganization(Organization $organization): bool
    {
        return (string) $organization->default_site_script_id === (string) $this->id;
    }

    public function displayName(): string
    {
        if ($this->source === self::SOURCE_MARKETPLACE) {
            return '[From marketplace] '.$this->name;
        }

        return $this->name;
    }
}
