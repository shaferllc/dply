<?php

namespace App\Models;

use App\Enums\TrialState;
use App\Services\Billing\OrganizationBillingStateComputer;
use App\Support\Beta\BetaProgram;
use App\Services\Billing\SubscriptionPlanResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
        'edge_data_region',
        'database_workspace_settings',
        'insights_preferences',
        'services_preferences',
        'alert_slack_webhook_url',
        'alert_extra_emails',
        // Billing entity. Used on Stripe invoices for this org's
        // subscription. Migrated off users in 2026-05.
        'invoice_email',
        'vat_number',
        'billing_currency',
        'billing_details',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'beta_joined_at' => 'datetime',
            'deploy_email_notifications_enabled' => 'boolean',
            'email_server_credentials_enabled' => 'boolean',
            'email_database_credentials_enabled' => 'boolean',
            'server_site_preferences' => 'array',
            'cron_maintenance_until' => 'datetime',
            'firewall_settings' => 'array',
            'database_workspace_settings' => 'array',
            'insights_preferences' => 'array',
            'services_preferences' => 'array',
            'alert_extra_emails' => 'array',
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
        return $this->trialState() === TrialState::ActiveTrial;
    }

    /**
     * Per-request memo for {@see owesNothingThisCycle}.
     */
    private ?bool $owesNothingMemo = null;

    /**
     * Resolve the org's current subscription-lifecycle state. The single
     * source of truth for "what can this org do right now?" — see
     * App\Enums\TrialState for the full state machine.
     */
    public function trialState(): TrialState
    {
        // Includes the cancel grace period — Cashier's valid() stays true
        // until ends_at, so a just-canceled org keeps full access.
        if ($this->onAnyPaidPlan()) {
            return TrialState::Subscribed;
        }

        // Closed-beta participants pay $0 with full access and are never paused:
        // the trial/pause ladder only protects dply from cost on orgs that
        // should be paying. Subscribed (early-subscribe) wins above; at the
        // global cutover isBeta() flips false and the org rejoins this ladder
        // (BetaGraduateCommand reseeds a fresh trial). NoTrial = free indefinitely.
        if ($this->isBeta()) {
            return TrialState::NoTrial;
        }

        $reference = $this->pauseLadderReference();
        if ($reference !== null) {
            // Free-plan exemption: an org that owes nothing this cycle (Free
            // plan, no managed products, no Edge usage) is never paused — the
            // pause ladder only protects dply from cost on orgs that should be
            // paying. Such an org simply lives on the free tier indefinitely.
            if ($this->owesNothingThisCycle()) {
                return TrialState::NoTrial;
            }

            $softPauseDays = (int) config('subscription.standard.soft_pause_days', 30);

            return $reference->copy()->addDays($softPauseDays)->isPast()
                ? TrialState::ExpiredHard
                : TrialState::ExpiredSoft;
        }

        if ($this->trial_ends_at === null) {
            return TrialState::NoTrial;
        }

        return TrialState::ActiveTrial;
    }

    /**
     * True when the org's current fleet bills to nothing this cycle: a Free
     * plan (within the free server ceiling) with no managed products and no
     * Edge delivery usage. Memoized per request — recomputing on every
     * {@see TrialState} call would be wasteful.
     */
    public function owesNothingThisCycle(): bool
    {
        return $this->owesNothingMemo ??= app(OrganizationBillingStateComputer::class)
            ->compute($this)
            ->isFree();
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
    private function pauseLadderReference(): ?CarbonInterface
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
    public function subscriptionEndsAt(): ?CarbonInterface
    {
        return $this->subscription('default')?->ends_at;
    }

    /**
     * When the soft-pause window flips to hard-pause. Null when not on a pause
     * track (subscribed, active trial, no trial recorded). Useful for "agent
     * disconnects on {date}" UI copy.
     */
    public function hardPauseStartsAt(): ?CarbonImmutable
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
        return $this->trialState() !== TrialState::ExpiredHard;
    }

    /**
     * The flat plan the org is currently on, resolved from its billable BYO
     * server count — the same basis the bill uses. Carries the plan's site
     * ceiling (`max_sites`).
     *
     * @return array{key: string, label: string, price_cents: int, max_servers: ?int, max_sites: ?int}
     */
    public function currentSubscriptionPlan(): array
    {
        return app(SubscriptionPlanResolver::class)
            ->resolveForServerCount($this->billablePlanServerCount());
    }

    /**
     * Billable BYO server count used to pick the plan. Mirrors the filter in
     * {@see OrganizationBillingStateComputer}: ready, past the new-server age
     * grace, and excluding dply-managed logical hosts.
     */
    private function billablePlanServerCount(): int
    {
        $minAgeDays = max(0, (int) config('subscription.standard.min_billable_age_days', 1));
        $ageCutoff = now()->subDays($minAgeDays);

        return $this->servers()
            ->where('status', Server::STATUS_READY)
            ->where('created_at', '<=', $ageCutoff)
            ->get()
            ->reject(fn (Server $server) => $server->isManagedProductHost())
            ->count();
    }

    /**
     * The org's current plan site ceiling, or null when unlimited.
     */
    public function planSiteLimit(): ?int
    {
        // Beta orgs use the roomy beta site ceiling instead of the plan tier.
        if ($this->isBeta()) {
            return max(1, (int) config('subscription.standard.beta.sites', 25));
        }

        return $this->currentSubscriptionPlan()['max_sites'];
    }

    /**
     * Number of sites that count against the plan's site ceiling. Preview
     * deployments (Edge/Cloud) are scratch clones of a parent and never
     * consume quota.
     */
    public function quotaCountedSiteCount(): int
    {
        return $this->sites()
            ->get()
            ->reject(fn (Site $site) => $site->isEdgePreview() || $site->isCloudPreview())
            ->count();
    }

    /**
     * True when the org has reached its plan's site ceiling.
     */
    public function siteLimitReached(): bool
    {
        $limit = $this->planSiteLimit();

        return $limit !== null && $this->quotaCountedSiteCount() >= $limit;
    }

    /**
     * Friendly upgrade prompt shown when site creation is blocked.
     */
    public function siteLimitMessage(): string
    {
        $plan = $this->currentSubscriptionPlan();
        $limit = $plan['max_sites'];

        if ($limit === null) {
            return '';
        }

        return sprintf(
            'Your %s plan includes %d %s. Add a server to move up to the next plan, or contact us to raise your limit.',
            $plan['label'],
            $limit,
            $limit === 1 ? 'site' : 'sites',
        );
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

    public function billingSnapshots(): HasMany
    {
        return $this->hasMany(OrganizationBillingSnapshot::class);
    }

    public function billingSubscriptionSyncEvents(): HasMany
    {
        return $this->hasMany(BillingSubscriptionSyncEvent::class);
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

    public function serverBlueprints(): HasMany
    {
        return $this->hasMany(ServerBlueprint::class);
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

    /**
     * Seed the member-role memo from an organization_user pivot that was
     * already loaded on this org instance (e.g. via $user->organizations()).
     */
    public function rememberMemberRoleFor(User $user, ?string $role): void
    {
        $userId = (string) $user->id;
        $this->memberRoleMemo[$userId] = $role;
        self::$memberRoleStaticMemo[(string) $this->id.':'.$userId] = $role;
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
     * True while this org is an active closed-beta participant: it redeemed an
     * invite (`beta_joined_at` set) AND the global beta program is still open.
     * After the cutover this flips false and the org rejoins the normal
     * plan/trial lifecycle.
     */
    public function isBeta(): bool
    {
        return $this->beta_joined_at !== null && BetaProgram::isOpen();
    }

    /**
     * True when the dply platform fee is waived this cycle: an active beta org
     * that has NOT subscribed early. Opting into a paid plan turns the waiver
     * off (the org wanted to pay) — but the free CX22 stays comped regardless,
     * via the comped_until column. Drives the $0 plan price in billing.
     */
    public function betaFeeWaived(): bool
    {
        return $this->isBeta() && ! $this->onAnyPaidPlan();
    }

    /**
     * BYO server ceiling for a beta org — generous enough to feel unlimited for
     * a solo dev / small team, bounded so a leaked invite can't provision
     * hundreds of boxes on a stolen cloud key via dply.
     */
    public function betaByoServerLimit(): int
    {
        return max(1, (int) config('subscription.standard.beta.byo_servers', 5));
    }

    /**
     * Free dply-managed server ceiling for a beta org — the single free CX22.
     */
    public function betaManagedServerLimit(): int
    {
        return max(0, (int) config('subscription.standard.beta.managed_servers', 1));
    }

    /**
     * BYO VMs that count against the beta BYO ceiling (excludes the free managed
     * box and managed-product logical hosts).
     */
    public function byoServerCount(): int
    {
        return $this->servers()
            ->where('hosting_backend', Server::HOSTING_BACKEND_BYO)
            ->get()
            ->reject(fn (Server $server) => $server->isManagedProductHost())
            ->count();
    }

    /**
     * dply-managed VMs the org currently holds (the free-CX22 grant counter).
     */
    public function managedServerCount(): int
    {
        return $this->servers()
            ->where('hosting_backend', Server::HOSTING_BACKEND_DPLY)
            ->get()
            ->filter(fn (Server $server) => $server->isManagedVm())
            ->count();
    }

    /**
     * Whether the org can provision another free dply-managed server. During
     * beta this enforces the single-CX22 grant; outside beta managed servers
     * aren't capped here (availability is gated by the surface flag + platform
     * config at the create flow).
     */
    public function canCreateManagedServer(): bool
    {
        if (! $this->isBeta()) {
            return true;
        }

        return $this->managedServerCount() < $this->betaManagedServerLimit();
    }

    /**
     * Maximum number of BYO servers allowed. Unlimited under the Standard model
     * — trial-state gating handles the cash-burning abuse case — but bounded for
     * beta orgs by the beta envelope.
     */
    public function maxServers(): int
    {
        return $this->isBeta() ? $this->betaByoServerLimit() : PHP_INT_MAX;
    }

    /**
     * Maximum sites allowed on the org's current plan. Returns PHP_INT_MAX for
     * the unlimited (Business / null) ceiling so callers can compare numerically.
     */
    public function maxSites(): int
    {
        return $this->planSiteLimit() ?? PHP_INT_MAX;
    }

    /**
     * Whether the organization can create another server (under limit).
     */
    public function canCreateServer(): bool
    {
        // Beta orgs are bounded by the BYO envelope (the free managed box is
        // counted separately via canCreateManagedServer); otherwise unlimited.
        if ($this->isBeta()) {
            return $this->byoServerCount() < $this->maxServers();
        }

        return $this->servers()->count() < $this->maxServers();
    }

    /**
     * Whether the organization can create another site under its current
     * plan's site ceiling. Preview deployments don't consume quota — see
     * {@see quotaCountedSiteCount()}.
     */
    public function canCreateSite(): bool
    {
        return ! $this->siteLimitReached();
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
     * True when the org has an active dply Standard subscription — i.e. it
     * carries any price dply owns under the plan model: a flat plan price
     * (Starter/Pro/Business, monthly or yearly) or any a-la-carte managed
     * product / Edge-usage price. A Free-plan org with no managed products has
     * no Stripe subscription at all and returns false here.
     */
    public function onStandardSubscription(): bool
    {
        return $this->subscriptionMatchesAnyPrice($this->standardStripePriceIds());
    }

    /**
     * Every Stripe price ID dply owns under the Standard plan model, across
     * both billing intervals.
     *
     * @return list<?string>
     */
    private function standardStripePriceIds(): array
    {
        $stripe = (array) config('subscription.standard.stripe', []);

        $ids = array_merge(
            array_values((array) ($stripe['plans'] ?? [])),
            array_values((array) ($stripe['plans_yearly'] ?? [])),
            [
                $stripe['serverless'] ?? null,
                $stripe['serverless_yearly'] ?? null,
                $stripe['cloud'] ?? null,
                $stripe['cloud_yearly'] ?? null,
                $stripe['edge'] ?? null,
                $stripe['edge_yearly'] ?? null,
                $stripe['edge_usage'] ?? null,
            ],
        );

        return array_values(array_map(
            fn ($id) => is_string($id) ? $id : null,
            $ids,
        ));
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
