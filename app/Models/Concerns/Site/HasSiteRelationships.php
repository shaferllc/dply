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
use App\Models\SiteTenantDomain;
use App\Models\SiteUptimeMonitor;
use App\Models\SiteWebserverConfigProfile;
use App\Models\User;
use App\Models\WebhookDeliveryLog;
use App\Models\Workspace;
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
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function webserverConfigProfile(): HasOne
    {
        return $this->hasOne(SiteWebserverConfigProfile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function deployScript(): BelongsTo
    {
        return $this->belongsTo(Script::class, 'deploy_script_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function dnsProviderCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class, 'dns_provider_credential_id');
    }

    public function edgeProviderCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class, 'edge_provider_credential_id');
    }

    public function serverlessProviderCredential(): BelongsTo
    {
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

    public function domains(): HasMany
    {
        return $this->hasMany(SiteDomain::class);
    }

    /**
     * The OpenWhisk actions on this serverless function-Site. A Site is an
     * OpenWhisk package: one `kind=code` action for a plain function, more
     * once the package model lands. Code actions sort before sequences.
     */
    public function functionActions(): HasMany
    {
        return $this->hasMany(FunctionAction::class)
            ->orderByRaw("CASE WHEN kind = 'code' THEN 0 ELSE 1 END")
            ->orderBy('name');
    }

    public function previewDomains(): HasMany
    {
        return $this->hasMany(SitePreviewDomain::class)->orderByDesc('is_primary')->orderBy('hostname');
    }

    public function domainAliases(): HasMany
    {
        return $this->hasMany(SiteDomainAlias::class)->orderBy('sort_order')->orderBy('hostname');
    }

    public function basicAuthUsers(): HasMany
    {
        return $this->hasMany(SiteBasicAuthUser::class)->orderBy('sort_order')->orderBy('username');
    }

    public function accessGate(): HasOne
    {
        return $this->hasOne(SiteAccessGate::class);
    }

    public function accessGatePasswords(): HasMany
    {
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

    public function uptimeMonitors(): HasMany
    {
        return $this->hasMany(SiteUptimeMonitor::class)->orderBy('sort_order')->orderBy('id');
    }

    public function tenantDomains(): HasMany
    {
        return $this->hasMany(SiteTenantDomain::class)->orderBy('sort_order')->orderBy('hostname');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(SiteCertificate::class)->latest('created_at');
    }

    public function deployments(): HasMany
    {
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

    public function webhookDeliveryLogs(): HasMany
    {
        return $this->hasMany(WebhookDeliveryLog::class)->orderByDesc('id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(SiteRelease::class)->orderByDesc('id');
    }

    public function processes(): HasMany
    {
        return $this->hasMany(SiteProcess::class)->orderBy('name');
    }

    /**
     * Databases on the site's server that belong to this site (via the
     * server_databases.site_id single-owner link). Server-wide databases
     * with a null site_id are not included — they surface only on the
     * server-level Databases manager.
     */
    public function serverDatabases(): HasMany
    {
        return $this->hasMany(ServerDatabase::class)->orderBy('name');
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(SiteBinding::class);
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(SiteRedirect::class)->orderBy('sort_order');
    }

    public function deployHooks(): HasMany
    {
        $relation = $this->hasMany(SiteDeployHook::class)->orderBy('sort_order');

        if ($this->active_deploy_pipeline_id) {
            $relation->where('pipeline_id', $this->active_deploy_pipeline_id);
        }

        return $relation;
    }

    public function deployPipelines(): HasMany
    {
        return $this->hasMany(SiteDeployPipeline::class)->orderBy('sort_order')->orderBy('name');
    }

    public function deploymentSchedules(): HasMany
    {
        return $this->hasMany(SiteDeploymentSchedule::class)->orderBy('created_at');
    }

    public function activeDeployPipeline(): BelongsTo
    {
        return $this->belongsTo(SiteDeployPipeline::class, 'active_deploy_pipeline_id');
    }

    /**
     * Ordered steps for the pipeline used on deploy (active pipeline).
     */
    public function deploySteps(): HasMany
    {
        $relation = $this->hasMany(SiteDeployStep::class)->orderBy('sort_order');

        if ($this->active_deploy_pipeline_id) {
            $relation->where('pipeline_id', $this->active_deploy_pipeline_id);
        }

        return $relation;
    }

    public function fileBackups(): HasMany
    {
        return $this->hasMany(SiteFileBackup::class)->orderByDesc('created_at');
    }

    public function edgeDeployments(): HasMany
    {
        return $this->hasMany(EdgeDeployment::class)->orderByDesc('created_at');
    }

    public function edgeSiteAccessRule(): HasOne
    {
        return $this->hasOne(EdgeSiteAccessRule::class);
    }

    public function edgeEnvVars(): HasMany
    {
        return $this->hasMany(EdgeSiteEnvVar::class)->orderBy('key');
    }

    public function edgeSiteMembers(): HasMany
    {
        return $this->hasMany(EdgeSiteMember::class);
    }

    public function deploySyncGroups(): BelongsToMany
    {
        return $this->belongsToMany(SiteDeploySyncGroup::class, 'site_deploy_sync_group_sites', 'site_id', 'site_deploy_sync_group_id')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function notificationSubscriptions(): MorphMany
    {
        return $this->morphMany(NotificationSubscription::class, 'subscribable');
    }

    public function insightSetting(): MorphOne
    {
        return $this->morphOne(InsightSetting::class, 'settingsable');
    }

    public function insightFindings(): HasMany
    {
        return $this->hasMany(InsightFinding::class)->orderByDesc('detected_at');
    }
}
