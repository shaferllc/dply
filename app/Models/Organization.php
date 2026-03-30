<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use Billable, HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'deploy_email_notifications_enabled',
        'server_site_preferences',
        'default_site_script_id',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'deploy_email_notifications_enabled' => 'boolean',
            'server_site_preferences' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Organization $organization): void {
            $organization->createDefaultTeamIfMissing();
        });
    }

    /**
     * Ensure a first team exists (idempotent). Used when model events are disabled (e.g. seeders) and after legacy org rows.
     */
    public function createDefaultTeamIfMissing(): Team
    {
        $existing = $this->teams()->orderBy('created_at')->first();
        if ($existing) {
            return $existing;
        }

        $base = Str::slug(__('general'));
        $slug = $base;
        $i = 0;
        while ($this->teams()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $this->teams()->create([
            'name' => __('General'),
            'slug' => $slug,
        ]);
    }

    /**
     * Add an organization member to the default (first) team as team admin when not already present.
     */
    public function attachUserToDefaultTeam(User $user, string $teamRole = 'admin'): void
    {
        if (! $this->hasMember($user)) {
            return;
        }

        $team = $this->createDefaultTeamIfMissing();
        if ($team->users()->where('user_id', $user->id)->exists()) {
            return;
        }

        $team->users()->attach($user->id, ['role' => $teamRole]);
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedServerSitePreferences(): array
    {
        $defaults = config('user_preferences.organization_server_site_defaults', []);
        $keys = array_keys($defaults);
        $stored = $this->server_site_preferences ?? [];

        return array_merge($defaults, array_intersect_key($stored, array_flip($keys)));
    }

    /**
     * Whether deploy-related email (immediate or digest) should go to org stakeholders.
     * Global app config still disables all deploy notifications when off.
     */
    public function wantsDeployEmailNotifications(): bool
    {
        return (bool) $this->deploy_email_notifications_enabled;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function webserverTemplates(): HasMany
    {
        return $this->hasMany(WebserverTemplate::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function organizationSshKeys(): HasMany
    {
        return $this->hasMany(OrganizationSshKey::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function scripts(): HasMany
    {
        return $this->hasMany(Script::class);
    }

    public function defaultSiteScript(): BelongsTo
    {
        return $this->belongsTo(Script::class, 'default_site_script_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function statusPages(): HasMany
    {
        return $this->hasMany(StatusPage::class);
    }

    public function providerCredentials(): HasMany
    {
        return $this->hasMany(ProviderCredential::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class, 'organization_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function integrationOutboundWebhooks(): HasMany
    {
        return $this->hasMany(IntegrationOutboundWebhook::class);
    }

    public function notificationChannels(): MorphMany
    {
        return $this->morphMany(NotificationChannel::class, 'owner');
    }

    public function hasMember(User $user): bool
    {
        return $this->users()->where('user_id', $user->id)->exists();
    }

    public function hasAdminAccess(User $user): bool
    {
        $pivot = $this->users()->where('user_id', $user->id)->first()?->pivot;

        return $pivot && in_array($pivot->role, ['owner', 'admin'], true);
    }

    public function userIsDeployer(User $user): bool
    {
        $pivot = $this->users()->where('user_id', $user->id)->first()?->pivot;

        return $pivot && $pivot->role === 'deployer';
    }

    /**
     * Maximum number of servers allowed for this organization based on subscription.
     * Free/Starter (no active subscription or non-Pro plan): config subscription.limits.servers_free (default 3).
     * Pro (pro_monthly or pro_yearly): unlimited.
     */
    public function maxServers(): int
    {
        $subscription = $this->subscription('default');
        if ($subscription && $subscription->valid()) {
            $plans = config('subscription.plans', []);
            $proPriceIds = array_filter([
                $plans['pro_monthly']['price_id'] ?? null,
                $plans['pro_yearly']['price_id'] ?? null,
            ]);
            foreach ($proPriceIds as $priceId) {
                if ($priceId && $subscription->hasPrice($priceId)) {
                    return PHP_INT_MAX;
                }
            }
        }

        return config('subscription.limits.servers_free', 3);
    }

    /**
     * Maximum sites allowed for this organization (count includes all servers).
     * Pro: unlimited. Otherwise {@see config('subscription.limits.sites_free')}.
     */
    public function maxSites(): int
    {
        $subscription = $this->subscription('default');
        if ($subscription && $subscription->valid()) {
            $plans = config('subscription.plans', []);
            $proPriceIds = array_filter([
                $plans['pro_monthly']['price_id'] ?? null,
                $plans['pro_yearly']['price_id'] ?? null,
            ]);
            foreach ($proPriceIds as $priceId) {
                if ($priceId && $subscription->hasPrice($priceId)) {
                    return PHP_INT_MAX;
                }
            }
        }

        return max(0, config('subscription.limits.sites_free', 10));
    }

    /**
     * Whether the organization can create another server (under limit).
     */
    public function canCreateServer(): bool
    {
        return $this->servers()->count() < $this->maxServers();
    }

    /**
     * Whether the organization can create another site (under {@see maxSites()}).
     */
    public function canCreateSite(): bool
    {
        return $this->sites()->count() < $this->maxSites();
    }

    /**
     * Human-readable server cap for the current plan (e.g. "3", "Unlimited").
     */
    public function maxServersDisplay(): string
    {
        $m = $this->maxServers();

        return $m >= PHP_INT_MAX ? 'Unlimited' : (string) $m;
    }

    /**
     * Human-readable site cap for the current plan (e.g. "10", "Unlimited").
     */
    public function maxSitesDisplay(): string
    {
        $m = $this->maxSites();

        return $m >= PHP_INT_MAX ? 'Unlimited' : (string) $m;
    }

    public function planTierLabel(): string
    {
        return $this->onProSubscription() ? 'Pro' : 'Free';
    }

    public function onProSubscription(): bool
    {
        $subscription = $this->subscription('default');
        if (! $subscription || ! $subscription->valid()) {
            return false;
        }
        $plans = config('subscription.plans', []);
        $proPriceIds = array_filter([
            $plans['pro_monthly']['price_id'] ?? null,
            $plans['pro_yearly']['price_id'] ?? null,
        ]);
        foreach ($proPriceIds as $priceId) {
            if ($priceId && $subscription->hasPrice($priceId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seat count from Stripe when seat billing is configured; null if not on Pro / not applicable.
     */
    public function seatCapFromSubscription(): ?int
    {
        if (! $this->onProSubscription()) {
            return null;
        }
        $sub = $this->subscription('default');
        if (! $sub) {
            return null;
        }
        $seatPriceId = trim((string) (config('subscription.plans.seat.price_id') ?? ''));
        if ($seatPriceId !== '' && $sub->hasPrice($seatPriceId)) {
            try {
                return max(1, (int) $sub->findItemOrFail($seatPriceId)->quantity);
            } catch (\Throwable) {
                return 1;
            }
        }
        foreach (['pro_monthly', 'pro_yearly'] as $key) {
            $pid = config("subscription.plans.{$key}.price_id");
            if (! $pid || ! $sub->hasPrice($pid)) {
                continue;
            }
            if ($sub->hasMultiplePrices()) {
                $item = $sub->items->firstWhere('stripe_price', $pid);

                return max(1, (int) ($item?->quantity ?? 1));
            }

            return max(1, (int) ($sub->quantity ?? 1));
        }

        return null;
    }

    /**
     * Maximum members + pending invites; null means unlimited.
     */
    public function effectiveMemberSeatCap(): ?int
    {
        $env = config('dply.max_organization_members');
        $stripeCap = $this->seatCapFromSubscription();
        if ($stripeCap !== null && $env !== null) {
            return min($env, $stripeCap);
        }

        return $stripeCap ?? $env;
    }
}
