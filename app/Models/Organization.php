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
        'email_server_credentials_enabled',
        'email_database_credentials_enabled',
        'server_site_preferences',
        'default_site_script_id',
        'cron_maintenance_until',
        'cron_maintenance_note',
        'firewall_settings',
        'database_workspace_settings',
        'insights_preferences',
        'services_preferences',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'deploy_email_notifications_enabled' => 'boolean',
            'email_server_credentials_enabled' => 'boolean',
            'email_database_credentials_enabled' => 'boolean',
            'server_site_preferences' => 'array',
            'cron_maintenance_until' => 'datetime',
            'firewall_settings' => 'array',
            'database_workspace_settings' => 'array',
            'insights_preferences' => 'array',
            'services_preferences' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            // 14-day no-card trial — committed pricing model. Skip if explicitly
            // set by the caller (factories, imports, fixtures) so they keep control.
            if ($organization->trial_ends_at === null) {
                $days = (int) config('subscription.standard.trial_days', 14);
                $organization->trial_ends_at = now()->addDays($days);
            }
        });

        static::created(function (Organization $organization): void {
            $organization->createDefaultTeamIfMissing();
        });
    }

    /**
     * True while the org is in its 14-day no-card trial window. Distinct from
     * Cashier's onTrial() (which only knows about Stripe-tracked trials) —
     * dply trials exist *before* a Stripe subscription is created.
     */
    public function onDplyTrial(): bool
    {
        return $this->trialState() === \App\Enums\TrialState::ActiveTrial;
    }

    /**
     * Resolve the org's current subscription-lifecycle state. The single
     * source of truth for "what can this org do right now?" — see
     * App\Enums\TrialState for the full state machine.
     */
    public function trialState(): \App\Enums\TrialState
    {
        // Includes the cancel grace period — Cashier's valid() stays true
        // until ends_at, so a just-canceled org keeps full access.
        if ($this->onAnyPaidPlan()) {
            return \App\Enums\TrialState::Subscribed;
        }

        $reference = $this->pauseLadderReference();
        if ($reference !== null) {
            $softPauseDays = (int) config('subscription.standard.soft_pause_days', 30);

            return $reference->copy()->addDays($softPauseDays)->isPast()
                ? \App\Enums\TrialState::ExpiredHard
                : \App\Enums\TrialState::ExpiredSoft;
        }

        if ($this->trial_ends_at === null) {
            return \App\Enums\TrialState::NoTrial;
        }

        return \App\Enums\TrialState::ActiveTrial;
    }

    /**
     * The date the soft → hard pause ladder is measured from, or null when the
     * org isn't on a pause track. Two sources, in priority order:
     *
     *  1. A subscription that has fully ended (canceled, past its grace period)
     *     — the org lapsed from a paid plan; measure from the subscription's
     *     end date.
     *  2. A trial that's already expired — measure from trial_ends_at.
     *
     * A future-dated trial returns null (still an active trial, not paused).
     */
    private function pauseLadderReference(): ?\Carbon\CarbonInterface
    {
        $subscription = $this->subscription('default');
        if ($subscription && $subscription->ended() && $subscription->ends_at !== null) {
            return $subscription->ends_at;
        }

        if ($this->trial_ends_at !== null && $this->trial_ends_at->isPast()) {
            return $this->trial_ends_at;
        }

        return null;
    }

    /**
     * True when the org's current pause state stems from a canceled/ended
     * subscription rather than an expired trial — drives banner copy.
     */
    public function lapsedFromSubscription(): bool
    {
        $subscription = $this->subscription('default');

        return $subscription !== null && $subscription->ended();
    }

    /**
     * True when the subscription is canceled but still inside the period the
     * customer already paid for — full access continues, billing stops at
     * {@see subscriptionEndsAt}.
     */
    public function onSubscriptionGracePeriod(): bool
    {
        $subscription = $this->subscription('default');

        return $subscription !== null && $subscription->onGracePeriod();
    }

    /**
     * The date a canceled subscription's access ends. Null when not canceled.
     */
    public function subscriptionEndsAt(): ?\Carbon\CarbonInterface
    {
        return $this->subscription('default')?->ends_at;
    }

    /**
     * When the soft-pause window flips to hard-pause. Null when not on a pause
     * track (subscribed, active trial, no trial recorded). Useful for "agent
     * disconnects on {date}" UI copy.
     */
    public function hardPauseStartsAt(): ?\Carbon\CarbonImmutable
    {
        if ($this->onAnyPaidPlan()) {
            return null;
        }

        $reference = $this->pauseLadderReference();
        if ($reference === null) {
            return null;
        }

        $softPauseDays = (int) config('subscription.standard.soft_pause_days', 30);

        return $reference->copy()->addDays($softPauseDays)->toImmutable();
    }

    /**
     * Gate for cash-burning deploy operations. False when in either expired-
     * trial state; true while on any active subscription or live trial.
     */
    public function canDeploy(): bool
    {
        return $this->trialState()->permitsBilledWork();
    }

    /**
     * Gate for the Run-Now scheduler button. Same policy as deploys: paused
     * accounts can't trigger fresh runs, but the cron-driven scheduler that
     * lives on the customer's own server continues independently of dply.
     */
    public function canSchedulerRun(): bool
    {
        return $this->trialState()->permitsBilledWork();
    }

    /**
     * Gate for incoming agent metrics. Day-45 hard-pause behavior — dply
     * stops accepting telemetry from the org's servers, which is where the
     * ongoing cost lives. Soft-paused orgs keep reporting so dashboards and
     * the billing page stay accurate while the customer is being prompted
     * to add a card.
     */
    public function acceptsMetrics(): bool
    {
        return $this->trialState() !== \App\Enums\TrialState::ExpiredHard;
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
     * Database workspace policy merged with config defaults (credential shares, import caps).
     *
     * @return array{credential_shares_enabled: bool, import_max_bytes: int|null}
     */
    public function mergedDatabaseWorkspaceSettings(): array
    {
        $defaults = config('server_database.organization_defaults', []);
        $keys = array_keys($defaults);
        $stored = $this->database_workspace_settings ?? [];

        return array_merge($defaults, array_intersect_key($stored, array_flip($keys)));
    }

    /**
     * @return array{digest_non_critical: bool, quiet_hours_enabled: bool, quiet_hours_start: int, quiet_hours_end: int}
     */
    public function mergedInsightsPreferences(): array
    {
        $defaults = config('insights.organization_defaults', []);
        $stored = is_array($this->insights_preferences) ? $this->insights_preferences : [];

        return array_replace_recursive($defaults, $stored);
    }

    /**
     * @return array{
     *     deployer_systemd_actions_enabled: bool,
     *     systemd_notifications_digest: 'immediate'|'hourly',
     *     systemd_status_only_units: list<string>
     * }
     */
    public function mergedServicesPreferences(): array
    {
        $defaults = config('server_services.organization_defaults', []);
        if (! is_array($defaults)) {
            $defaults = [];
        }
        $stored = is_array($this->services_preferences) ? $this->services_preferences : [];

        $merged = array_replace_recursive($defaults, $stored);
        $digest = (string) ($merged['systemd_notifications_digest'] ?? 'immediate');
        if (! in_array($digest, ['immediate', 'hourly'], true)) {
            $digest = 'immediate';
        }
        $norm = static function (mixed $v): string {
            if (! is_string($v)) {
                return '';
            }

            return strtolower(trim(str_replace('.service', '', $v)));
        };
        $globalOnly = config('server_services.systemd_status_only_units', []);
        $globalOnly = is_array($globalOnly) ? $globalOnly : [];
        $fromGlobal = array_filter(array_map($norm, $globalOnly), static fn (string $v) => $v !== '');
        $statusOnly = $merged['systemd_status_only_units'] ?? [];
        if (! is_array($statusOnly)) {
            $statusOnly = [];
        }
        $fromOrg = array_filter(array_map($norm, $statusOnly), static fn (string $v) => $v !== '');
        $statusOnly = array_values(array_unique(array_merge($fromGlobal, $fromOrg)));

        return [
            'deployer_systemd_actions_enabled' => (bool) ($merged['deployer_systemd_actions_enabled'] ?? false),
            'systemd_notifications_digest' => $digest,
            'systemd_status_only_units' => $statusOnly,
        ];
    }

    public function allowsDatabaseCredentialShares(): bool
    {
        return (bool) ($this->mergedDatabaseWorkspaceSettings()['credential_shares_enabled'] ?? true);
    }

    /**
     * Effective SQL import size limit for this org (bytes), never above app import_max_bytes.
     */
    public function databaseImportMaxBytes(): int
    {
        $global = (int) config('server_database.import_max_bytes', 10485760);
        $raw = $this->mergedDatabaseWorkspaceSettings()['import_max_bytes'] ?? null;
        if ($raw === null || $raw === '') {
            return $global;
        }

        return min(max((int) $raw, 1024), $global);
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

    public function cronJobTemplates(): HasMany
    {
        return $this->hasMany(OrganizationCronJobTemplate::class);
    }

    public function supervisorProgramTemplates(): HasMany
    {
        return $this->hasMany(OrganizationSupervisorProgramTemplate::class);
    }

    public function firewallRuleTemplates(): HasMany
    {
        return $this->hasMany(FirewallRuleTemplate::class);
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

    public function backupConfigurations(): HasMany
    {
        return $this->hasMany(BackupConfiguration::class);
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

    public function notificationWebhookDestinations(): HasMany
    {
        return $this->hasMany(NotificationWebhookDestination::class);
    }

    public function notificationChannels(): MorphMany
    {
        return $this->morphMany(NotificationChannel::class, 'owner');
    }

    /**
     * Per-request memo for the current user's pivot role on this org.
     * Without this, hasMember / hasAdminAccess / userIsDeployer each
     * fire their own join against organization_user on every gate call —
     * the debug bar showed the same row queried back-to-back from
     * Organization.php#314, #319, #326 on a single page render.
     *
     * Keyed by user id so the cache works across users (e.g. when an
     * admin views a teammate's permissions on the same org instance).
     *
     * @var array<string, ?string> user-id => role (or null when not a member)
     */
    private array $memberRoleMemo = [];

    /**
     * Class-level cache keyed by `{organization_id}:{user_id}` so two different
     * Organization instances representing the same row (e.g. one from
     * $user->currentOrganization(), one from $server->organization) share a
     * single pivot lookup. The previous instance-level memo only saved
     * repeated calls on the same instance, which left ~3 duplicate queries
     * per page render.
     *
     * @var array<string, ?string>
     */
    private static array $memberRoleStaticMemo = [];

    private function memberRole(User $user): ?string
    {
        $userId = (string) $user->id;
        if (array_key_exists($userId, $this->memberRoleMemo)) {
            return $this->memberRoleMemo[$userId];
        }

        $staticKey = (string) $this->id.':'.$userId;
        if (array_key_exists($staticKey, self::$memberRoleStaticMemo)) {
            return $this->memberRoleMemo[$userId] = self::$memberRoleStaticMemo[$staticKey];
        }

        $pivot = $this->users()->where('user_id', $user->id)->first()?->pivot;
        $role = $pivot ? (string) $pivot->role : null;

        self::$memberRoleStaticMemo[$staticKey] = $role;

        return $this->memberRoleMemo[$userId] = $role;
    }

    /** Drop the cross-instance member-role cache (between requests in long-running processes / tests). */
    public static function flushMemberRoleCache(): void
    {
        self::$memberRoleStaticMemo = [];
    }

    public function hasMember(User $user): bool
    {
        return $this->memberRole($user) !== null;
    }

    public function hasAdminAccess(User $user): bool
    {
        return in_array($this->memberRole($user), ['owner', 'admin'], true);
    }

    public function userIsDeployer(User $user): bool
    {
        return $this->memberRole($user) === 'deployer';
    }

    /**
     * Maximum number of servers allowed. Always unlimited under the Standard
     * model — trial-state gating (see canDeploy / acceptsMetrics) handles the
     * cash-burning abuse case, so there's no need for an arbitrary server cap.
     */
    public function maxServers(): int
    {
        return PHP_INT_MAX;
    }

    /**
     * Maximum sites allowed. Always unlimited; the marketing page commits to
     * "no per-site billing" and that's enforced here.
     */
    public function maxSites(): int
    {
        return PHP_INT_MAX;
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
        if ($this->onEnterpriseSubscription()) {
            return 'Enterprise';
        }
        if ($this->onStandardSubscription()) {
            return 'Standard';
        }

        return 'Trial';
    }

    /**
     * True when this org has any active paid subscription — Standard or Enterprise.
     * Used as the "paying customer" gate by feature flags, API token creation, etc.
     */
    public function onAnyPaidPlan(): bool
    {
        return $this->onStandardSubscription() || $this->onEnterpriseSubscription();
    }

    /**
     * True when the org is on the Standard plan (base price + per-server tiers).
     */
    public function onStandardSubscription(): bool
    {
        return $this->subscriptionMatchesAnyPrice([
            config('subscription.standard.stripe.base_monthly'),
            config('subscription.standard.stripe.base_yearly'),
        ]);
    }

    /**
     * True when the org has a sales-led Enterprise subscription.
     */
    public function onEnterpriseSubscription(): bool
    {
        return $this->subscriptionMatchesAnyPrice([
            config('subscription.enterprise.stripe_price_id'),
        ]);
    }

    /**
     * @param  list<?string>  $priceIds
     */
    private function subscriptionMatchesAnyPrice(array $priceIds): bool
    {
        $subscription = $this->subscription('default');
        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        foreach ($priceIds as $priceId) {
            if (is_string($priceId) && $priceId !== '' && $subscription->hasPrice($priceId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seat cap from Stripe is not part of the Standard pricing story — every
     * paid plan gets unlimited team members. Kept as a stub returning null so
     * {@see effectiveMemberSeatCap} can fall through to the env-level cap.
     */
    public function seatCapFromSubscription(): ?int
    {
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
