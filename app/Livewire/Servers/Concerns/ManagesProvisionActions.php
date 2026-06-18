<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Enums\ServerProvider;
use App\Jobs\ProvisionAwsEc2ServerJob;
use App\Jobs\ProvisionAzureServerJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Jobs\ProvisionOracleServerJob;
use App\Jobs\ProvisionOvhServerJob;
use App\Jobs\ProvisionUpCloudServerJob;
use App\Jobs\ProvisionVultrServerJob;
use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Modules\TaskRunner\Services\TaskRunnerService;
use App\Support\Servers\ProvisionPipelineLog;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesProvisionActions
{


    public function openCancelProvisionModal(): void
    {
        $this->authorize('update', $this->server);
        $this->showCancelProvisionModal = true;
    }

    public function closeCancelProvisionModal(): void
    {
        $this->showCancelProvisionModal = false;
    }

    public function cancelProvision(TaskRunnerService $taskRunner): void
    {
        $this->authorize('update', $this->server);

        $task = $this->activeProvisionTask();
        if (! $task) {
            $this->toastError(__('There is no active build task to cancel right now.'));
            $this->showCancelProvisionModal = false;

            return;
        }

        $result = $taskRunner->cancelTask((string) $task->id);

        if (! ($result['success'] ?? false)) {
            $this->toastError((string) ($result['error'] ?? __('Could not cancel the build task.')));

            return;
        }

        $this->server->refresh();
        $this->showCancelProvisionModal = false;
        $this->toastSuccess(__('Build cancelled. You can keep this server or remove it.'));
    }

    public function cancelProvisionAndOpenDelete(TaskRunnerService $taskRunner): void
    {
        $this->authorize('delete', $this->server);

        $task = $this->activeProvisionTask();
        if ($task) {
            $result = $taskRunner->cancelTask((string) $task->id);

            if (! ($result['success'] ?? false)) {
                $this->toastError((string) ($result['error'] ?? __('Could not cancel the build task.')));

                return;
            }
        }

        $this->server->refresh();
        $this->showCancelProvisionModal = false;
        $this->openRemoveServerModal();
    }

    public function openResumeInstallModal(): void
    {
        $this->authorize('update', $this->server);
        $this->showResumeInstallModal = true;
    }

    public function closeResumeInstallModal(): void
    {
        $this->showResumeInstallModal = false;
    }

    public function rerunSetup(): void
    {
        $this->authorize('update', $this->server);
        $this->showResumeInstallModal = false;

        $server = $this->server->fresh();
        if (! $server || ! RunSetupScriptJob::shouldDispatch($server)) {
            $this->toastError('This server is not ready for a setup re-run yet.');

            return;
        }

        $meta = $server->meta ?? [];
        unset($meta['provision_task_id']);
        unset($meta['provision_step_snapshots']);

        $server->update([
            'setup_status' => Server::SETUP_STATUS_PENDING,
            'meta' => $meta,
        ]);

        $fresh = $server->fresh();
        if ($fresh) {
            ProvisionPipelineLog::info('server.provision.journey.rerun_setup_dispatched', $fresh, [
                'phase' => 'ui',
            ]);
        }
        WaitForServerSshReadyJob::dispatch($fresh ?? $server);

        $this->redirectRoute('servers.journey', $server, navigate: true);
    }

    /**
     * Re-dispatch the provider-specific provision job when the cloud-side
     * call failed (e.g. region/size mismatch) before any provider resource
     * was created. Only safe to call while the server has no provider_id,
     * otherwise we'd create a duplicate cloud resource.
     */
    public function retryCloudProvision(): void
    {
        $this->authorize('update', $this->server);

        $server = $this->server->fresh();
        if (! $server || ! $this->canRetryCloudProvision($server)) {
            $this->toastError(__('This server cannot be retried — it already has a provider resource or is not in a failed cloud-provision state.'));

            return;
        }

        $job = $this->provisionJobClassFor($server);
        if ($job === null) {
            $this->toastError(__('Retry is not supported for this provider yet.'));

            return;
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        unset($meta['provision_error']);
        unset($meta['auto_retry_at'], $meta['auto_retry_attempt'], $meta['auto_retry_max']);

        $server->forceFill([
            'status' => Server::STATUS_PENDING,
            'meta' => $meta,
        ])->save();

        $fresh = $server->fresh() ?? $server;

        ProvisionPipelineLog::info('server.provision.journey.retry_cloud_dispatched', $fresh, [
            'phase' => 'ui',
            'provider' => $fresh->provider?->value,
        ]);

        $job::dispatch($fresh);

        $this->redirectRoute('servers.journey', $fresh, navigate: true);
    }

    protected function canRetryCloudProvision(Server $server): bool
    {
        if ($server->status !== Server::STATUS_ERROR) {
            return false;
        }

        // Setup-side failures are handled by Resume install — only retry
        // pre-SSH cloud-side failures here.
        if ($server->setup_status === Server::SETUP_STATUS_FAILED) {
            return false;
        }

        // Server already exists at the provider — re-dispatching would
        // create a duplicate. Operator should remove and recreate instead.
        if (filled($server->provider_id)) {
            return false;
        }

        return $this->provisionJobClassFor($server) !== null;
    }

    /**
     * Drop the failed server row and bounce the operator back to the
     * create wizard with a fresh draft pre-filled from this server's
     * saved fields. Only safe when no provider resource was created.
     * The user lands on step 2 (Where) so they can change the bad
     * region/size before re-submitting.
     */
    public function editAndRetry(): void
    {
        $this->authorize('update', $this->server);
        $this->authorize('delete', $this->server);

        $server = $this->server->fresh();
        if (! $server || ! $this->canRetryCloudProvision($server)) {
            $this->toastError(__('This server can no longer be edited — its provider resource exists or it is mid-flight.'));

            return;
        }

        $user = auth()->user();
        $org = $server->organization;

        if ($user === null || $org === null) {
            $this->toastError(__('You are not in an organization context — cannot restore the wizard draft.'));

            return;
        }

        $payload = $this->buildDraftPayloadFromServer($server);

        /** @var ServerCreateDraft $draft */
        $draft = ServerCreateDraft::query()->firstOrNew([
            'user_id' => $user->getKey(),
            'organization_id' => $org->getKey(),
        ]);

        $draft->payload = $payload;
        // Land on step 2 (Where) so region/size — the most common fix —
        // is right in front of the operator.
        $draft->step = 2;
        $draft->bumpExpiry();
        $draft->save();

        ProvisionPipelineLog::info('server.provision.journey.edit_and_retry_dispatched', $server, [
            'phase' => 'ui',
            'provider' => $server->provider?->value,
        ]);

        // Drop the failed server — no provider resource exists so this
        // is safe. The wizard will create a fresh row when the operator
        // re-submits.
        $server->delete();

        $this->redirectRoute('servers.create.where', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildDraftPayloadFromServer(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $cacheServer = is_array($meta['cache_server'] ?? null) ? $meta['cache_server'] : [];

        // Re-encrypt the cache password from meta if present (it was stored
        // encrypted with the same app key). For the draft restore path,
        // leaving it blank and asking the operator to re-enter is the
        // safe default — the form field is encrypted-on-save anyway and
        // we don't want to expose the plaintext just to round-trip it.
        return [
            'mode' => 'provider',
            'type' => $server->provider?->value ?? '',
            'name' => (string) ($server->name ?? ''),
            'provider_credential_id' => (string) ($server->provider_credential_id ?? ''),
            'region' => (string) ($server->region ?? ''),
            'size' => (string) ($server->size ?? ''),
            'setup_script_key' => (string) ($server->setup_script_key ?? ''),
            'server_role' => (string) ($meta['server_role'] ?? 'application'),
            'install_profile' => (string) ($meta['install_profile'] ?? 'laravel_app'),
            'webserver' => (string) ($meta['webserver'] ?? 'nginx'),
            'php_version' => (string) ($meta['php_version'] ?? '8.3'),
            'database' => (string) ($meta['database'] ?? 'mysql84'),
            'cache_service' => (string) ($meta['cache_service'] ?? 'redis'),
            'cache_remote_access' => (bool) ($cacheServer['remote_access'] ?? false),
            'cache_allowed_from' => (string) ($cacheServer['allowed_from'] ?? ''),
            'cache_require_password' => (bool) ($cacheServer['require_password'] ?? false),
            'cache_password' => '',
        ];
    }

    /**
     * @return class-string|null
     */
    protected function provisionJobClassFor(Server $server): ?string
    {
        return match ($server->provider) {
            ServerProvider::DigitalOcean => ProvisionDigitalOceanDropletJob::class,
            ServerProvider::Hetzner => ProvisionHetznerServerJob::class,
            ServerProvider::Linode => ProvisionLinodeServerJob::class,
            ServerProvider::Vultr => ProvisionVultrServerJob::class,
            ServerProvider::Ovh => ProvisionOvhServerJob::class,
            ServerProvider::UpCloud => ProvisionUpCloudServerJob::class,
            ServerProvider::Aws => ProvisionAwsEc2ServerJob::class,
            ServerProvider::Azure => ProvisionAzureServerJob::class,
            ServerProvider::Oracle => ProvisionOracleServerJob::class,
            default => null,
        };
    }
}
