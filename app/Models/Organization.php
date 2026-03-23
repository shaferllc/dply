<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use Billable, HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'email',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
        ];
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

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
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
     * Whether the organization can create another server (under limit).
     */
    public function canCreateServer(): bool
    {
        return $this->servers()->count() < $this->maxServers();
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
