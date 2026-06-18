<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Handlers;

use App\Enums\SiteType;
use App\Jobs\ProvisionSiteJob;
use App\Models\ImportMigrationStep;
use App\Models\ImportServerMigration;
use App\Models\ImportSiteMigration;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Modules\Imports\Services\StepHandler;
use App\Modules\Imports\Services\WaitForTargetServerException;
use App\Services\Sites\SiteProvisioner;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the dply Site row for a migrating site from the frozen
 * source_snapshot, mirroring the field shape used by Livewire\Sites\Create.
 * Sets ImportSiteMigration.target_site_id so subsequent handlers (clone,
 * env, etc.) can find it.
 *
 * Idempotent: if target_site_id is already set, no-op and return the
 * existing Site. Requires the target Server to be in READY status (otherwise
 * the Site row would be orphaned).
 */
class CreateTargetSiteHandler implements StepHandler
{
    public function __construct(protected SiteProvisioner $provisioner) {}

    public static function key(): string
    {
        return ImportMigrationStep::KEY_CREATE_TARGET_SITE;
    }

    public function execute(ImportMigrationStep $step): void
    {
        if ($step->import_site_migration_id === null) {
            throw new RuntimeException('create_target_site requires a site-scoped step.');
        }

        $child = ImportSiteMigration::find($step->import_site_migration_id);
        if ($child === null) {
            throw new RuntimeException('Child migration missing for create_target_site.');
        }

        if ($child->target_site_id !== null) {
            return;
        }

        $migration = ImportServerMigration::find($child->import_server_migration_id);
        $target = $migration?->target_server_id ? Server::find($migration->target_server_id) : null;
        if ($target === null) {
            throw new RuntimeException('Target dply server missing.');
        }
        if ($target->status !== Server::STATUS_READY) {
            throw new WaitForTargetServerException(sprintf(
                'Cannot create site on dply server %s while status is %s.',
                $target->name,
                $target->status,
            ));
        }

        $snapshot = $child->source_snapshot ?? [];
        $domain = $child->domain;
        $repoUrl = $this->resolveRepositoryUrl($snapshot);
        $branch = (string) ($snapshot['branch'] ?? $snapshot['repository_branch'] ?? 'main');
        $webDir = $this->nullableString($snapshot['web_directory'] ?? null) ?? '/public';
        $phpVersion = $this->nullableString($snapshot['php_version'] ?? null);

        $site = Site::query()->create([
            'server_id' => $target->id,
            'user_id' => $migration->user_id,
            'organization_id' => $target->organization_id,
            'name' => $domain,
            'slug' => Str::slug($domain) ?: 'imported-'.Str::lower(Str::random(6)),
            'type' => SiteType::Php,
            'runtime' => 'php',
            'runtime_version' => $phpVersion,
            'document_root' => $webDir,
            'status' => Site::STATUS_PENDING,
            'git_repository_url' => $repoUrl,
            'git_branch' => $branch,
            'webhook_secret' => Str::random(48),
            'deploy_strategy' => 'simple',
            'releases_to_keep' => 5,
            'laravel_scheduler' => $child->site_type === 'laravel',
            'deployment_environment' => 'production',
            'restart_supervisor_programs_after_deploy' => false,
            'meta' => [
                'imported_from' => [
                    'source' => $child->source,
                    'source_site_id' => $child->source_site_id,
                    'migration_id' => $migration->id,
                ],
            ],
        ]);

        $site->ensureUniqueSlug();
        $site->save();

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => strtolower($domain),
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        $child->target_site_id = $site->id;
        $child->status = ImportSiteMigration::STATUS_STAGING;
        $child->save();

        // Kick off dply's standard site provisioning (creates system user, site dir,
        // nginx vhost, database). Subsequent SshDependent handlers (CloneRepo, etc.)
        // verify the site directory exists before running and throw a wait exception
        // if provisioning is still in flight; ProvisionSiteJob completion is observed
        // via Site::status transition similarly to how ServerObserver wakes us.
        $site->loadMissing(['server', 'domains']);
        $this->provisioner->markQueued($site);
        ProvisionSiteJob::dispatch($site->id);

        $step->result_data = ['site_id' => $site->id, 'provision_dispatched' => true];
        $step->save();
    }

    /**
     * @param  array<string, mixed> $snapshot
     */
    protected function resolveRepositoryUrl(array $snapshot): ?string
    {
        if (! empty($snapshot['repository_url']) && is_string($snapshot['repository_url'])) {
            return $snapshot['repository_url'];
        }
        $repo = $snapshot['repository'] ?? null;
        $provider = $snapshot['repository_provider'] ?? null;
        if (! is_string($repo) || ! is_string($provider)) {
            return is_string($repo) ? $repo : null;
        }

        return match ($provider) {
            'github' => "git@github.com:{$repo}.git",
            'gitlab' => "git@gitlab.com:{$repo}.git",
            'bitbucket' => "git@bitbucket.org:{$repo}.git",
            default => $repo,
        };
    }

    protected function nullableString(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $trimmed = trim($v);

        return $trimmed === '' ? null : $trimmed;
    }
}
