<?php

namespace App\Models;

use Database\Factories\ScriptFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property ?string $user_id
 * @property string $name
 * @property string $content
 * @property ?string $run_as_user
 * @property string $source
 * @property ?string $marketplace_key
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Organization $organization
 * @property-read ?User $user
 * @property-read Collection<int, Site> $sitesUsingAsDeploy
 */
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

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Site, $this> */
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
