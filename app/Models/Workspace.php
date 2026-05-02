<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Workspace extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'slug',
        'description',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace): void {
            if (empty($workspace->slug)) {
                $workspace->slug = Str::slug($workspace->name) ?: 'project';
            }

            $base = $workspace->slug;
            $n = 0;
            while (static::query()
                ->where('organization_id', $workspace->organization_id)
                ->where('slug', $workspace->slug)
                ->exists()) {
                $n++;
                $workspace->slug = $base.'-'.$n;
            }
        });

        static::created(function (Workspace $workspace): void {
            if (! $workspace->members()->where('user_id', $workspace->user_id)->exists()) {
                $workspace->members()->create([
                    'user_id' => $workspace->user_id,
                    'role' => WorkspaceMember::ROLE_OWNER,
                ]);
            }

            if (! $workspace->environments()->exists()) {
                $workspace->environments()->createMany([
                    [
                        'name' => 'Production',
                        'slug' => 'production',
                        'description' => 'Live production resources for this project.',
                        'sort_order' => 1,
                    ],
                    [
                        'name' => 'Staging',
                        'slug' => 'staging',
                        'description' => 'Pre-production validation resources.',
                        'sort_order' => 2,
                    ],
                    [
                        'name' => 'Development',
                        'slug' => 'development',
                        'description' => 'Internal development and testing resources.',
                        'sort_order' => 3,
                    ],
                ]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'notes' => 'string',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class)->orderBy('created_at');
    }

    public function environments(): HasMany
    {
        return $this->hasMany(WorkspaceEnvironment::class)->orderBy('sort_order')->orderBy('name');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(WorkspaceLabel::class, 'workspace_label_assignments')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function runbooks(): HasMany
    {
        return $this->hasMany(WorkspaceRunbook::class)->orderBy('sort_order')->orderBy('title');
    }

    public function variables(): HasMany
    {
        return $this->hasMany(WorkspaceVariable::class)->orderBy('env_key');
    }

    public function deployRuns(): HasMany
    {
        return $this->hasMany(WorkspaceDeployRun::class)->orderByDesc('created_at');
    }

    public function notificationSubscriptions(): MorphMany
    {
        return $this->morphMany(NotificationSubscription::class, 'subscribable');
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    public function memberRole(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return $this->members()
            ->where('user_id', $user->id)
            ->value('role');
    }

    public function userCanView(User $user): bool
    {
        if ($this->organization_id !== $user->currentOrganization()?->id) {
            return false;
        }

        return $this->organization->hasAdminAccess($user) || $this->hasMember($user);
    }

    public function userCanUpdate(User $user): bool
    {
        if (! $this->userCanView($user)) {
            return false;
        }

        if ($this->organization->hasAdminAccess($user)) {
            return true;
        }

        return in_array($this->memberRole($user), [
            WorkspaceMember::ROLE_OWNER,
            WorkspaceMember::ROLE_MAINTAINER,
        ], true);
    }

    public function userCanManageMembers(User $user): bool
    {
        if ($this->organization->hasAdminAccess($user)) {
            return true;
        }

        return $this->memberRole($user) === WorkspaceMember::ROLE_OWNER;
    }

    public function userCanDeploy(User $user): bool
    {
        if ($this->organization->hasAdminAccess($user)) {
            return true;
        }

        return in_array($this->memberRole($user), [
            WorkspaceMember::ROLE_OWNER,
            WorkspaceMember::ROLE_MAINTAINER,
            WorkspaceMember::ROLE_DEPLOYER,
        ], true);
    }
}
