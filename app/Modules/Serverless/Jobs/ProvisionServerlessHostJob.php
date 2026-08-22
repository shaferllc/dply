<?php

namespace App\Modules\Serverless\Jobs;

use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
// Unqualified, this resolves to App\Modules\Serverless\Jobs\RunSiteDeploymentJob
// which does not exist — a fatal on dispatch. No test covers this path.
use App\Modules\Providers\Services\DigitalOceanService;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Modules\Serverless\Support\ServerlessCustomerCopy;
use App\Modules\Serverless\Support\ServerlessHostCredential;
use App\Modules\Serverless\Support\ServerlessPlatformContext;
use App\Services\Providers\ProviderCredentialHealth;
use App\Support\DeployLogRedactor;
use App\Support\Providers\ProviderAuthFailure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        // Managed mode: dply runs the function on its OWN, pre-provisioned
        // DigitalOcean Functions namespace. There's no per-host namespace to
        // create and no customer credential — just stamp the shared platform
        // OpenWhisk credentials and mark the host ready.
        //
        // Checked BEFORE the already-provisioned short-circuit on purpose:
        // config is the source of truth for a managed host, so a rotated
        // platform key has to overwrite the stamp. Short-circuiting first
        // pinned the old key forever and every deploy 401'd.
        if (! empty($meta['serverless_managed'] ?? null)) {
            $platform = ServerlessPlatformContext::fromConfig();
            if (! $platform->configured()) {
                Log::warning('serverless.namespace.managed_not_configured', ['server_id' => $server->id]);
                $this->markFailed(
                    $server,
                    'dply-managed serverless is not configured — the platform functions namespace is missing.',
                );

                return;
            }

            $meta['digitalocean_functions'] = $platform->openWhiskCredentials();
            $this->markReady($server, $meta);

            $this->deployConfiguredFunctions($server);

            return;
        }

        // BYO namespace, already provisioned — skip the API call, just
        // (re)deploy functions. The customer's own key lives on the host and
        // config has nothing newer to offer, so the stamp stands.
        if (! empty($meta['digitalocean_functions']['api_host'] ?? null)) {
            // A functions namespace has no SSH setup phase — make sure the
            // host reads as fully ready so the workspace navigation shows.
            if (
                $server->status !== Server::STATUS_READY
                || $server->setup_status !== Server::SETUP_STATUS_DONE
                || isset($meta['provision_error'])
            ) {
                $this->markReady($server, $meta);
            }
            $this->deployConfiguredFunctions($server);

            return;
        }

        $namespace = $this->provisionNamespace($server);
        if ($namespace === null) {
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
            $this->markFailed(
                $server,
                'The functions host created a namespace but returned credentials that cannot be used. Retry provisioning.',
            );

            return;
        }

        $meta['digitalocean_functions'] = [
            'api_host' => $namespace['api_host'],
            'namespace' => $namespace['namespace'],
            'access_key' => $namespace['access_key'],
        ];
        $this->markReady($server, $meta);

        $this->deployConfiguredFunctions($server);
    }

    /**
     * Create the functions namespace, remapping off a rejected token first.
     * A live 401 stamps that credential and retries once with a healthy sibling.
     *
     * @return array{api_host: string, namespace: string, access_key: string, region: string}|null
     */
    private function provisionNamespace(Server $server): ?array
    {
        $credential = ServerlessHostCredential::resolveForProvision($server);
        if ($credential === null) {
            $hadAttached = filled($server->provider_credential_id);
            Log::warning($hadAttached ? 'serverless.namespace.unhealthy_credential' : 'serverless.namespace.no_credential', [
                'server_id' => $server->id,
                'credential_id' => $server->provider_credential_id,
            ]);
            $this->markFailed(
                $server,
                $hadAttached
                    ? ServerlessHostCredential::UNUSABLE_MESSAGE
                    : 'This function has no provider credential, so the namespace could not be created. Connect an account and retry.',
            );

            return null;
        }

        try {
            return $this->createNamespace($server, $credential);
        } catch (Throwable $e) {
            return $this->retryNamespaceAfterAuthFailure($server, $credential, $e);
        }
    }

    /**
     * @return array{api_host: string, namespace: string, access_key: string, region: string}
     */
    private function createNamespace(Server $server, ProviderCredential $credential): array
    {
        return (new DigitalOceanService($credential))->createFunctionsNamespace(
            $server->region !== '' ? (string) $server->region : 'nyc1',
            'dply-'.$server->id,
        );
    }

    /**
     * @return array{api_host: string, namespace: string, access_key: string, region: string}|null
     */
    private function retryNamespaceAfterAuthFailure(Server $server, ProviderCredential $credential, Throwable $e): ?array
    {
        if (! ProviderAuthFailure::detected($e->getMessage())) {
            Log::error('serverless.namespace.provision_failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($server, $e->getMessage());

            return null;
        }

        app(ProviderCredentialHealth::class)->markUnhealthy($credential, $e->getMessage());
        $server->unsetRelation('providerCredential');

        $replacement = ServerlessHostCredential::resolveForProvision($server);
        if ($replacement === null || $replacement->is($credential)) {
            Log::warning('serverless.namespace.unhealthy_credential', [
                'server_id' => $server->id,
                'credential_id' => $credential->id,
            ]);
            $this->markFailed($server, ServerlessHostCredential::UNUSABLE_MESSAGE);

            return null;
        }

        try {
            return $this->createNamespace($server, $replacement);
        } catch (Throwable $retryError) {
            Log::error('serverless.namespace.provision_failed', [
                'server_id' => $server->id,
                'error' => $retryError->getMessage(),
            ]);
            $this->markFailed(
                $server,
                ProviderAuthFailure::detected($retryError->getMessage())
                    ? ServerlessHostCredential::UNUSABLE_MESSAGE
                    : $retryError->getMessage(),
            );

            return null;
        }
    }

    /**
     * Persist a secret-safe namespace failure onto the host so Journey can
     * show the real reason instead of a canned sentence.
     */
    private function markFailed(Server $server, string $message): void
    {
        $safe = Str::limit(DeployLogRedactor::redact(trim(ServerlessCustomerCopy::neutralize($message))), 500, '…');
        if ($safe === '') {
            $safe = 'Could not create the functions namespace.';
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        $meta['provision_error'] = [
            'stage' => 'namespace',
            'message' => $safe,
            'at' => now()->toIso8601String(),
        ];

        $server->update([
            'status' => Server::STATUS_ERROR,
            'meta' => $meta,
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function markReady(Server $server, array $meta): void
    {
        unset($meta['provision_error']);

        $server->update([
            'meta' => $meta,
            'status' => Server::STATUS_READY,
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);
    }

    /**
     * Kick a deploy for every function-Site on this host that's been
     * configured but not yet deployed.
     */
    private function deployConfiguredFunctions(Server $server): void
    {
        $server->sites()
            ->whereIn('status', [
                Site::STATUS_FUNCTIONS_CONFIGURED,
                Site::STATUS_FUNCTIONS_FAILED,
            ])
            ->get()
            ->each(fn (Site $site) => RunSiteDeploymentJob::dispatch($site, SiteDeployment::TRIGGER_MANUAL));
    }
}
