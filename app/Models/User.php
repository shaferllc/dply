<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'email',
    'dply_auth_id',
    'password',
    'country_code',
    'locale',
    'timezone',
    'invoice_email',
    'vat_number',
    'billing_currency',
    'billing_details',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'two_factor_confirmed_at',
    'referral_code',
    'ui_preferences',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (empty($user->referral_code)) {
                $user->referral_code = static::newUniqueReferralCode();
            }
        });
    }

    public static function newUniqueReferralCode(): string
    {
        do {
            $code = Str::lower(Str::random(20));
        } while (static::query()->where('referral_code', $code)->exists());

        return $code;
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function providerCredentials(): HasMany
    {
        return $this->hasMany(ProviderCredential::class, 'user_id');
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'user_id');
    }

    public function sshKeys(): HasMany
    {
        return $this->hasMany(UserSshKey::class);
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function notificationChannels(): MorphMany
    {
        return $this->morphMany(NotificationChannel::class, 'owner');
    }

    public function backupConfigurations(): HasMany
    {
        return $this->hasMany(BackupConfiguration::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    /**
     * Users who registered with this user’s referral code.
     *
     * @return HasMany<User, User>
     */
    public function referredUsers(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_user_id');
    }

    /**
     * Bonus rows granted to this user as the referrer.
     *
     * @return HasMany<ReferralReward, User>
     */
    public function referralRewardsGranted(): HasMany
    {
        return $this->hasMany(ReferralReward::class, 'referrer_user_id');
    }

    public function currentOrganization(): ?Organization
    {
        $id = session('current_organization_id');
        if (! $id) {
            return $this->organizations()
                ->orderByPivot('created_at')
                ->orderBy('organizations.id')
                ->first();
        }

        return $this->organizations()->find($id);
    }

    /**
     * Active team scope for the current organization (session). Null means all teams in the org.
     */
    public function currentTeam(): ?Team
    {
        $org = $this->currentOrganization();
        if (! $org) {
            return null;
        }

        $teamId = session('current_team_id');
        if (! $teamId) {
            return null;
        }

        $team = Team::query()
            ->whereKey($teamId)
            ->where('organization_id', $org->id)
            ->first();

        if (! $team || ! $org->hasMember($this)) {
            return null;
        }

        return $team;
    }

    /**
     * Teams the user may scope the UI to within an organization (org members see all teams in that org).
     *
     * @return Collection<int, Team>
     */
    public function accessibleTeamsForOrganization(?Organization $org = null): Collection
    {
        $org ??= $this->currentOrganization();
        if (! $org || ! $org->hasMember($this)) {
            return new Collection([]);
        }

        return $org->teams()->orderBy('name')->get();
    }

    public function hasVerifiedEmail(): bool
    {
        if (! config('dply.require_email_verification')) {
            return true;
        }

        return parent::hasVerifiedEmail();
    }

    /**
     * Count sites in this user's organizations whose Git remote URL appears to use the given host (e.g. github.com).
     */
    public function gitHostRepositoryCount(string $hostFragment): int
    {
        $orgIds = $this->organizations()->pluck('organizations.id');
        if ($orgIds->isEmpty()) {
            return 0;
        }

        return Site::query()
            ->whereIn('organization_id', $orgIds)
            ->whereNotNull('git_repository_url')
            ->where('git_repository_url', 'like', '%'.$hostFragment.'%')
            ->count();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'referral_converted_at' => 'datetime',
            'ui_preferences' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedUiPreferences(): array
    {
        $defaults = config('user_preferences.defaults', []);
        $keys = array_keys($defaults);
        $stored = $this->ui_preferences ?? [];

        return array_merge($defaults, array_intersect_key($stored, array_flip($keys)));
    }
}
