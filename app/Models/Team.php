<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'preferences',
    ];

    protected function casts(): array
    {
        return [
            'preferences' => 'array',
        ];
    }

    /**
     * Team-level defaults for servers and sites (list behavior, sorting).
     * Falls back to legacy keys stored on the organization before teams had their own column.
     *
     * @return array<string, mixed>
     */
    public function mergedTeamPreferences(): array
    {
        $defaults = config('user_preferences.team_server_site_defaults', []);
        $keys = array_keys($defaults);
        $stored = $this->preferences ?? [];
        $merged = array_merge($defaults, array_intersect_key($stored, array_flip($keys)));

        $org = $this->organization;
        if ($org) {
            $legacyOrg = $org->server_site_preferences ?? [];
            foreach ($keys as $key) {
                if (! array_key_exists($key, $stored) && array_key_exists($key, $legacyOrg)) {
                    $merged[$key] = $legacyOrg[$key];
                }
            }
        }

        return $merged;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function hasMember(User $user): bool
    {
        return $this->users()->where('user_id', $user->id)->exists();
    }

    /**
     * Team admins or organization owners/admins may manage team SSH keys.
     */
    public function userCanManageSshKeys(User $user): bool
    {
        if ($this->organization->hasAdminAccess($user)) {
            return true;
        }

        return $this->users()->where('user_id', $user->id)->wherePivot('role', 'admin')->exists();
    }

    /**
     * Team admins or organization owners/admins may manage team notification channels.
     */
    public function userCanManageNotificationChannels(User $user): bool
    {
        return $this->userCanManageSshKeys($user);
    }

    public function sshKeys(): HasMany
    {
        return $this->hasMany(TeamSshKey::class);
    }

    public function notificationChannels(): MorphMany
    {
        return $this->morphMany(NotificationChannel::class, 'owner');
    }
}
