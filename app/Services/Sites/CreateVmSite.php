<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Enums\SiteType;
use App\Jobs\ProvisionSiteJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use App\Modules\Deploy\Services\SiteDeployPipelineManager;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create an ordinary site on a server the organization owns — the BYO
 * the BYO site-creation action.
 *
 * **Scope, deliberately narrow.** This covers a normal webserver host: PHP,
 * static, or Node, served by the server's webserver. It refuses a functions
 * host, a Docker host, a Kubernetes cluster, and a headless (`webserver=none`)
 * host, because each of those needs meta the create wizard builds from
 * capability-specific form state — internal port allocation, runtime targets,
 * container registries — and reproducing that here would be guesswork.
 *
 * ponytail: ordinary webserver hosts only; the exotic hosts stay on the wizard
 * until its create path is extracted into this action rather than duplicated.
 * That extraction is what would let both surfaces share one chain, the way
 * ServerlessCreateGate and CloudCreateGate already do for their gates.
 *
 * @see docs/adr/cli-init-and-site-creation.md
 */
final class CreateVmSite
{
    public function __construct(
        private readonly SiteProvisioner $provisioner,
        private readonly SiteDeployPipelineManager $pipelines,
    ) {}

    /**
     * True when this action can create on the given host at all.
     */
    public static function supports(Server $server): bool
    {
        if ($server->hostCapabilities()->supportsFunctionDeploy()
            || $server->isDockerHost()
            || $server->isKubernetesCluster()) {
            return false;
        }

        return ($server->meta['webserver'] ?? 'nginx') !== 'none';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(User $user, Server $server, array $payload): Site
    {
        if (! self::supports($server)) {
            throw new InvalidArgumentException(
                'This server runs a host type the CLI cannot create sites on yet. Create it in the dashboard instead.'
            );
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('A site name is required.');
        }

        $type = (string) ($payload['type'] ?? 'php');
        if (! in_array($type, ['php', 'static', 'node'], true)) {
            throw new InvalidArgumentException('Site type must be php, static, or node.');
        }

        $documentRoot = trim((string) ($payload['document_root'] ?? ''));
        if ($documentRoot === '') {
            throw new InvalidArgumentException('A document root is required.');
        }

        $hostname = strtolower(trim((string) ($payload['primary_hostname'] ?? '')));

        // Resolve slug collisions before the insert: (server_id, slug) is a
        // unique constraint and ensureUniqueSlug() only dedupes pre-save.
        $baseSlug = Str::slug($name) ?: 'site';
        $slug = $baseSlug;
        $suffix = 1;
        while (Site::query()->where('server_id', $server->id)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        $gitUrl = trim((string) ($payload['git_repository_url'] ?? ''));
        $branch = trim((string) ($payload['git_branch'] ?? '')) ?: 'main';
        $runtime = trim((string) ($payload['runtime'] ?? '')) ?: $type;
        $runtimeVersion = trim((string) ($payload['runtime_version'] ?? ''));

        $site = Site::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $server->organization_id,
            'deploy_script_id' => $server->organization?->default_site_script_id,
            'name' => $name,
            'slug' => $slug,
            'type' => SiteType::from($type),
            'runtime' => $runtime,
            'runtime_version' => $runtimeVersion !== '' ? $runtimeVersion : null,
            'build_command' => ($b = trim((string) ($payload['build_command'] ?? ''))) !== '' ? $b : null,
            'start_command' => ($s = trim((string) ($payload['start_command'] ?? ''))) !== '' ? $s : null,
            'document_root' => $documentRoot,
            'repository_path' => ($r = trim((string) ($payload['repository_path'] ?? ''))) !== '' ? $r : null,
            'app_port' => $type === 'node' ? ($payload['app_port'] ?? null) : null,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'git_repository_url' => $gitUrl !== '' ? $gitUrl : null,
            'git_branch' => $branch,
            'webhook_secret' => Str::random(48),
            'deploy_strategy' => 'simple',
            'releases_to_keep' => 5,
            'laravel_scheduler' => false,
            'deployment_environment' => 'production',
            'restart_supervisor_programs_after_deploy' => false,
            // Empty for an ordinary webserver host — every meta branch the
            // wizard builds belongs to a host type refused above.
            'meta' => [],
            'env_file_content' => ($e = trim((string) ($payload['env_file_content'] ?? ''))) !== '' ? $e : null,
        ]);

        $site->ensureUniqueSlug();
        $site->save();

        // The Site::created hook makes a `web` process with a null command.
        // A detected start command belongs on it immediately.
        if ($site->start_command !== null) {
            $site->processes()
                ->where('type', \App\Models\SiteProcess::TYPE_WEB)
                ->update(['command' => $site->start_command]);
        }

        // Give the first deploy sensible build/release steps rather than an
        // empty pipeline.
        $this->pipelines->seedRuntimeDefaults(
            $site,
            $site->runtime,
            ($framework = trim((string) ($payload['framework'] ?? ''))) !== '' ? $framework : null,
        );

        // A primary domain is optional — dply provisions a testing hostname
        // regardless — so only record one the caller actually supplied.
        if ($hostname !== '') {
            SiteDomain::query()->create([
                'site_id' => $site->id,
                'hostname' => $hostname,
                'is_primary' => true,
                'www_redirect' => false,
            ]);
        }

        $site->loadMissing(['server', 'domains']);
        $this->provisioner->markQueued($site);
        ProvisionSiteJob::dispatch($site->id);

        return $site;
    }
}
