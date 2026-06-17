<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Models\EdgeDeployment;
use App\Models\EdgeSiteAccessRule;
use App\Models\EdgeSiteEnvVar;
use App\Models\EdgeSiteMember;
use App\Models\FunctionAction;
use App\Models\InsightFinding;
use App\Models\InsightSetting;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProviderCredential;
use App\Models\Script;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteAccessGate;
use App\Models\SiteAccessGatePassword;
use App\Models\SiteBackend;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteBinding;
use App\Models\SiteCertificate;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
use App\Models\SiteDeploymentSchedule;
use App\Models\SiteDeployPipeline;
use App\Models\SiteDeployStep;
use App\Models\SiteDeploySyncGroup;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SiteFileBackup;
use App\Models\SitePreviewDomain;
use App\Models\SiteProcess;
use App\Models\SiteRedirect;
use App\Models\SiteRelease;
use App\Models\SiteSecretResidency;
use App\Models\SiteTenantDomain;
use App\Models\SiteUptimeMonitor;
use App\Models\SiteWebserverConfigProfile;
use App\Models\User;
use App\Models\WebhookDeliveryLog;
use App\Models\WorkerPool;
use App\Models\Workspace;
use App\Services\Sites\SecretResidencyResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
 */
trait HasSiteRelationships
{
    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /**
     * The serving points of a multi-backend site (this site behind a balancer on
     * ≥2 app servers). Empty for an ordinary single-server site. See
     * docs/MULTI_BACKEND_SITES.md. *
 * @return HasMany<SiteBackend, $this>
 */
    public function backends(): HasMany {
        return $this->hasMany(SiteBackend::class);
    }

    /**
     * Worker pools "attached" to this site: those that scale out the site's OWN
     * server (WorkerPool.source_server_id == this site's server_id). A pool runs
     * the source server's code + queues, so it belongs only to sites on that box —
     * NOT to every site in the workspace. Not a true relation (resolved via the
     * source-server FK), so this returns a collection. Drives the Workers panel.
     *
     * @return Collection<int, WorkerPool>
     */
    public function attachedWorkerPools(): Collection
    {
        // Explicit attachments (see {@see workerPools()}) win: once an operator
        // defines the set on the Worker servers page, it fully controls which
        // pools serve this site — even pools whose source server is another box.
        $explicit = $this->workerPools()
            ->with(['servers', 'primaryServer'])
            ->orderBy('created_at')
            ->get();

        if ($explicit->isNotEmpty()) {
            return $explicit;
        }

        // Back-compat fallback (no explicit attachments): a pool that scales this
        // site's OWN server — its source_server_id. A pool runs the source
        // server's code + queues, so by default it belongs to sites on that box.
        $serverId = $this->server_id;
        if ($serverId === null) {
            return new Collection;
        }

        return WorkerPool::query()
            ->where('source_server_id', $serverId)
            ->with(['servers', 'primaryServer'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Explicitly-attached worker pools (the operator-defined set). Many-to-many
     * so a site can be served by several pools and a pool can serve several sites. *
 * @return BelongsToMany<WorkerPool, $this>
 */
    public function workerPools(): BelongsToMany {
        return $this->belongsToMany(WorkerPool::class, 'site_worker_pool')->withTimestamps();
    }

    /**
     * Worker pools in this site's organization that are NOT currently attached —
     * the candidates the Worker servers picker offers. Lets the operator see what
     * workers exist (answering "I have workers, why aren't they here?") and pick.
     *
     * @return Collection<int, WorkerPool>
     */
    public function availableWorkerPools(): Collection
    {
        if ($this->organization_id === null) {
            return new Collection;
        }

        $attachedIds = $this->attachedWorkerPools()->pluck('id')->all();

        return WorkerPool::query()
            ->where('organization_id', $this->organization_id)
            ->whereNotIn('id', $attachedIds)
            ->with(['servers', 'sourceServer'])
            ->orderBy('name')
            ->get();
    }

    /** @return HasOne<SiteWebserverConfigProfile, $this> */
    public function webserverConfigProfile(): HasOne {
        return $this->hasOne(SiteWebserverConfigProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Script, $this> */
    public function deployScript(): BelongsTo {
        return $this->belongsTo(Script::class, 'deploy_script_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function dnsProviderCredential(): BelongsTo {
        return $this->belongsTo(ProviderCredential::class, 'dns_provider_credential_id');
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function edgeProviderCredential(): BelongsTo {
        return $this->belongsTo(ProviderCredential::class, 'edge_provider_credential_id');
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function serverlessProviderCredential(): BelongsTo {
        return $this->belongsTo(ProviderCredential::class, 'serverless_provider_credential_id');
    }

    /**
     * Provider credential used for DNS automation on this site (preview hostnames, DNS-01 defaults, etc.).
     * Uses the site override when set and DNS-capable; otherwise the latest DNS-capable credential for the organization (any provider).
     */
    public function dnsAutomationCredential(): ?ProviderCredential
    {
        $this->loadMissing('dnsProviderCredential');

        if ($this->dns_provider_credential_id) {
            $explicit = $this->dnsProviderCredential;
            if ($explicit !== null
                && $explicit->organization_id === $this->organization_id
                && $explicit->supportsDnsAutomation()) {
                return $explicit;
            }
        }

        if ($this->organization_id === null) {
            return null;
        }

        return ProviderCredential::query()
            ->where('organization_id', $this->organization_id)
            ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
            ->latest('updated_at')
            ->first();
    }

    /** @return HasMany<SiteDomain, $this> */
    public function domains(): HasMany {
        return $this->hasMany(SiteDomain::class);
    }

    /**
     * The OpenWhisk actions on this serverless function-Site. A Site is an
     * OpenWhisk package: one `kind=code` action for a plain function, more
     * once the package model lands. Code actions sort before sequences. *
 * @return HasMany<FunctionAction, $this>
 */
    public function functionActions(): HasMany {
        return $this->hasMany(FunctionAction::class)
            ->orderByRaw("CASE WHEN kind = 'code' THEN 0 ELSE 1 END")
            ->orderBy('name');
    }

    /** @return HasMany<SitePreviewDomain, $this> */
    public function previewDomains(): HasMany {
        return $this->hasMany(SitePreviewDomain::class)->orderByDesc('is_primary')->orderBy('hostname');
    }

    /** @return HasMany<SiteDomainAlias, $this> */
    public function domainAliases(): HasMany {
        return $this->hasMany(SiteDomainAlias::class)->orderBy('sort_order')->orderBy('hostname');
    }

    /** @return HasMany<SiteBasicAuthUser, $this> */
    public function basicAuthUsers(): HasMany {
        return $this->hasMany(SiteBasicAuthUser::class)->orderBy('sort_order')->orderBy('username');
    }

    /** @return HasOne<SiteAccessGate, $this> */
    public function accessGate(): HasOne {
        return $this->hasOne(SiteAccessGate::class);
    }

    /** @return HasMany<SiteAccessGatePassword, $this> */
    public function accessGatePasswords(): HasMany {
        return $this->hasMany(SiteAccessGatePassword::class)->orderBy('sort_order')->orderBy('label');
    }

    /**
     * Password gate credentials that should be written to config.json and enforced.
     *
     * @return Collection<int, SiteAccessGatePassword>
     */
    public function enforceableAccessGatePasswords(): Collection
    {
        $this->loadMissing('accessGatePasswords');

        return $this->accessGatePasswords->reject(
            fn (SiteAccessGatePassword $row): bool => $row->isPendingRemoval(),
        )->values();
    }

    /**
     * Subset of {@see basicAuthUsers()} that the webserver should actually
     * enforce: managed (Dply wrote the htpasswd) AND not pending-removal
     * (the next apply will drop them). Both the nginx config builder and the
     * htpasswd-sync helper must use this same subset — otherwise the config
     * can reference an htpasswd file the sync just deleted, locking everyone
     * out with a 500 from nginx.
     *
     * @return Collection<int, SiteBasicAuthUser>
     */
    public function enforceableBasicAuthUsers(): Collection
    {
        $this->loadMissing('basicAuthUsers');

        return $this->basicAuthUsers->reject(
            fn (SiteBasicAuthUser $u): bool => $u->isPendingRemoval() || $u->isDiscoveredFromServer()
        )->values();
    }

    /** @return HasMany<SiteUptimeMonitor, $this> */
    public function uptimeMonitors(): HasMany {
        return $this->hasMany(SiteUptimeMonitor::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<SiteTenantDomain, $this> */
    public function tenantDomains(): HasMany {
        return $this->hasMany(SiteTenantDomain::class)->orderBy('sort_order')->orderBy('hostname');
    }

    /** @return HasMany<SiteCertificate, $this> */
    public function certificates(): HasMany {
        return $this->hasMany(SiteCertificate::class)->latest('created_at');
    }

    /** @return HasMany<SiteDeployment, $this> */
    public function deployments(): HasMany {
        return $this->hasMany(SiteDeployment::class)->orderByDesc('id');
    }

    /**
     * Convenience accessor for the most recent SiteDeployment by start
     * time. Used by dashboard headers and "latest deploy" badges so
     * callers don't have to repeatedly write the orderBy + limit.
     */
    public function latestDeployment(): ?SiteDeployment
    {
        return once(function (): ?SiteDeployment {
            if ($this->relationLoaded('deployments')) {
                return $this->deployments
                    ->sortByDesc(fn (SiteDeployment $deployment): int => $deployment->started_at?->getTimestamp() ?? 0)
                    ->first();
            }

            // deployments() pre-orders by id desc; reorder() so started_at
            // is the only sort (ULIDs / backdated rows may disagree with id).
            return $this->deployments()->reorder('started_at', 'desc')->first();
        });
    }

    /** @return HasMany<WebhookDeliveryLog, $this> */
    public function webhookDeliveryLogs(): HasMany {
        return $this->hasMany(WebhookDeliveryLog::class)->orderByDesc('id');
    }

    /** @return HasMany<SiteRelease, $this> */
    public function releases(): HasMany {
        return $this->hasMany(SiteRelease::class)->orderByDesc('id');
    }

    /** @return HasMany<SiteProcess, $this> */
    public function processes(): HasMany {
        return $this->hasMany(SiteProcess::class)->orderBy('name');
    }

    /**
     * Databases on the site's server that belong to this site (via the
     * server_databases.site_id single-owner link). Server-wide databases
     * with a null site_id are not included — they surface only on the
     * server-level Databases manager. *
 * @return HasMany<ServerDatabase, $this>
 */
    public function serverDatabases(): HasMany {
        return $this->hasMany(ServerDatabase::class)->orderBy('name');
    }

    /** @return HasMany<SiteBinding, $this> */
    public function bindings(): HasMany {
        return $this->hasMany(SiteBinding::class);
    }

    /**
     * Per-key secret residency records — the env vars this site keeps OUT of the
     * loose plaintext-in-DB `.env` blob (escrowed under an org key, or referenced
     * from an external store). The blob carries only placeholders for these keys;
     * {@see SecretResidencyResolver} resolves them at push. *
 * @return HasMany<SiteSecretResidency, $this>
 */
    public function secretResidencies(): HasMany {
        return $this->hasMany(SiteSecretResidency::class);
    }

    /** @return HasMany<SiteRedirect, $this> */
    public function redirects(): HasMany {
        return $this->hasMany(SiteRedirect::class)->orderBy('sort_order');
    }

    /** @return HasMany<SiteDeployHook, $this> */
    public function deployHooks(): HasMany {
        $relation = $this->hasMany(SiteDeployHook::class)->orderBy('sort_order');

        if ($this->active_deploy_pipeline_id) {
            $relation->where('pipeline_id', $this->active_deploy_pipeline_id);
        }

        return $relation;
    }

    /** @return HasMany<SiteDeployPipeline, $this> */
    public function deployPipelines(): HasMany {
        return $this->hasMany(SiteDeployPipeline::class)->orderBy('sort_order')->orderBy('name');
    }

    /** @return HasMany<SiteDeploymentSchedule, $this> */
    public function deploymentSchedules(): HasMany {
        return $this->hasMany(SiteDeploymentSchedule::class)->orderBy('created_at');
    }

    /** @return BelongsTo<SiteDeployPipeline, $this> */
    public function activeDeployPipeline(): BelongsTo {
        return $this->belongsTo(SiteDeployPipeline::class, 'active_deploy_pipeline_id');
    }

    /**
     * Ordered steps for the pipeline used on deploy (active pipeline). *
 * @return HasMany<SiteDeployStep, $this>
 */
    public function deploySteps(): HasMany {
        $relation = $this->hasMany(SiteDeployStep::class)->orderBy('sort_order');

        if ($this->active_deploy_pipeline_id) {
            $relation->where('pipeline_id', $this->active_deploy_pipeline_id);
        }

        return $relation;
    }

    /** @return HasMany<SiteFileBackup, $this> */
    public function fileBackups(): HasMany {
        return $this->hasMany(SiteFileBackup::class)->orderByDesc('created_at');
    }

    /** @return HasMany<EdgeDeployment, $this> */
    public function edgeDeployments(): HasMany {
        return $this->hasMany(EdgeDeployment::class)->orderByDesc('created_at');
    }

    /** @return HasOne<EdgeSiteAccessRule, $this> */
    public function edgeSiteAccessRule(): HasOne {
        return $this->hasOne(EdgeSiteAccessRule::class);
    }

    /** @return HasMany<EdgeSiteEnvVar, $this> */
    public function edgeEnvVars(): HasMany {
        return $this->hasMany(EdgeSiteEnvVar::class)->orderBy('key');
    }

    /** @return HasMany<EdgeSiteMember, $this> */
    public function edgeSiteMembers(): HasMany {
        return $this->hasMany(EdgeSiteMember::class);
    }

    /** @return BelongsToMany<SiteDeploySyncGroup, $this> */
    public function deploySyncGroups(): BelongsToMany {
        return $this->belongsToMany(SiteDeploySyncGroup::class, 'site_deploy_sync_group_sites', 'site_id', 'site_deploy_sync_group_id')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /** @return MorphMany<NotificationSubscription, $this> */
    public function notificationSubscriptions(): MorphMany {
        return $this->morphMany(NotificationSubscription::class, 'subscribable');
    }

    /** @return MorphOne<InsightSetting, $this> */
    public function insightSetting(): MorphOne {
        return $this->morphOne(InsightSetting::class, 'settingsable');
    }

    /** @return HasMany<InsightFinding, $this> */
    public function insightFindings(): HasMany {
        return $this->hasMany(InsightFinding::class)->orderByDesc('detected_at');
    }
}
