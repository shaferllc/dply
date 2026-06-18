<?php

namespace App\Modules\Serverless\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Modules\Serverless\Support\ServerlessPlatformContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provisions the DigitalOcean Functions namespace that backs a serverless
 * host Server, then kicks the deploy of any function-Sites waiting on it.
 *
 * A serverless host has no machine — what it needs is a namespace's
 * OpenWhisk credentials (api_host + access_key). The function deploy engine
 * reads those from `server.meta.digitalocean_functions`; this job is what
 * puts them there.
 *
 * Idempotent: if the namespace metadata is already present, it skips the
 * DO API call and just (re)dispatches the deploys.
 */
class ProvisionServerlessHostJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public string $serverId) {}

    public function uniqueId(): string
    {
        return $this->serverId;
    }

    public function handle(): void
    {
        $server = Server::find($this->serverId);
        if (! $server || ! $server->isDigitalOceanFunctionsHost()) {
            return;
        }

        $meta = $server->meta;

        // Already provisioned — skip the API call, just (re)deploy functions.
        if (! empty($meta['digitalocean_functions']['api_host'] ?? null)) {
            // A functions namespace has no SSH setup phase — make sure the
            // host reads as fully ready so the workspace navigation shows.
            if ($server->status !== Server::STATUS_READY || $server->setup_status !== Server::SETUP_STATUS_DONE) {
                $server->update([
                    'status' => Server::STATUS_READY,
                    'setup_status' => Server::SETUP_STATUS_DONE,
                ]);
            }
            $this->deployConfiguredFunctions($server);

            return;
        }

        // Managed mode: dply runs the function on its OWN, pre-provisioned
        // DigitalOcean Functions namespace. There's no per-host namespace to
        // create and no customer credential — just stamp the shared platform
        // OpenWhisk credentials and mark the host ready.
        if (! empty($meta['serverless_managed'] ?? null)) {
            $platform = ServerlessPlatformContext::fromConfig();
            if (! $platform->configured()) {
                Log::warning('serverless.namespace.managed_not_configured', ['server_id' => $server->id]);
                $server->update(['status' => Server::STATUS_ERROR]);

                return;
            }

            $meta['digitalocean_functions'] = $platform->openWhiskCredentials();
            $server->update([
                'meta' => $meta,
                'status' => Server::STATUS_READY,
                'setup_status' => Server::SETUP_STATUS_DONE,
            ]);

            $this->deployConfiguredFunctions($server);

            return;
        }

        $credential = $server->providerCredential;
        if ($credential === null) {
            Log::warning('serverless.namespace.no_credential', ['server_id' => $server->id]);
            $server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        try {
            $namespace = (new DigitalOceanService($credential))->createFunctionsNamespace(
                $server->region !== '' ? (string) $server->region : 'nyc1',
                'dply-'.$server->id,
            );
        } catch (Throwable $e) {
            Log::error('serverless.namespace.provision_failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
            $server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        // The deploy authenticates against OpenWhisk with a `uuid:key`
        // access key. Catch a malformed credential here — at the namespace
        // stage — rather than letting the deploy fail later with a cryptic
        // "id:secret format" error.
        if (! str_contains((string) $namespace['access_key'], ':')) {
            Log::error('serverless.namespace.bad_access_key', [
                'server_id' => $server->id,
                'returned_keys' => array_keys($namespace),
            ]);
            $server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        $meta['digitalocean_functions'] = [
            'api_host' => $namespace['api_host'],
            'namespace' => $namespace['namespace'],
            'access_key' => $namespace['access_key'],
        ];
        $server->update([
            'meta' => $meta,
            'status' => Server::STATUS_READY,
            // No SSH setup phase for a functions namespace — it is done.
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);

        $this->deployConfiguredFunctions($server);
    }

    /**
     * Kick a deploy for every function-Site on this host that's been
     * configured but not yet deployed.
     */
    private function deployConfiguredFunctions(Server $server): void
    {
        $server->sites()
            ->where('status', Site::STATUS_FUNCTIONS_CONFIGURED)
            ->get()
            ->each(fn (Site $site) => RunSiteDeploymentJob::dispatch($site, SiteDeployment::TRIGGER_MANUAL));
    }
}
