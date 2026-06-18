<?php

declare(strict_types=1);

namespace App\Actions\Servers\Concerns;

use App\Enums\ServerProvider;
use App\Jobs\ProvisionAzureServerJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Jobs\ProvisionOracleServerJob;
use App\Jobs\ProvisionUpCloudServerJob;
use App\Jobs\ProvisionVultrServerJob;
use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Modules\Cloud\Services\HetznerService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait StoresOtherProviderServers
{


    /**
     * @param  list<string>  $scriptKeys
     */
    private function storeHetzner(User $user, Organization $org, ServerCreateForm $form, array $scriptKeys): Server
    {
        Validator::make(
            [
                'name' => $form->name,
                'provider_credential_id' => $form->provider_credential_id,
                'region' => $form->region,
                'size' => $form->size,
                'setup_script_key' => $form->setup_script_key,
            ],
            [
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:50',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]
        )->validate();

        $credential = ProviderCredential::where('organization_id', $org->id)
            ->where('provider', 'hetzner')
            ->findOrFail($form->provider_credential_id);

        $this->validateHetznerRegionSizeCombo($credential, $form->region, $form->size);

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $networkId = $form->hetzner_network_id !== '' ? (int) $form->hetzner_network_id : null;

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Hetzner,
            'provider_credential_id' => $credential->id,
            'region' => $form->region,
            'size' => $form->size,
            'setup_script_key' => $setupScriptKey,
            'setup_status' => $setupStatus,
            'meta' => $this->meta($form),
            'hetzner_network_id' => $networkId !== null ? (string) $networkId : null,
            'status' => Server::STATUS_PENDING,
        ]);

        ProvisionHetznerServerJob::dispatch($server);
        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

    /**
     * @param  list<string>  $scriptKeys
     */
    private function storeLinode(User $user, Organization $org, ServerCreateForm $form, array $scriptKeys): Server
    {
        Validator::make(
            [
                'name' => $form->name,
                'provider_credential_id' => $form->provider_credential_id,
                'region' => $form->region,
                'size' => $form->size,
                'setup_script_key' => $form->setup_script_key,
            ],
            [
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:50',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]
        )->validate();

        $credential = ProviderCredential::where('organization_id', $org->id)
            ->where('provider', 'linode')
            ->findOrFail($form->provider_credential_id);

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Linode,
            'provider_credential_id' => $credential->id,
            'region' => $form->region,
            'size' => $form->size,
            'setup_script_key' => $setupScriptKey,
            'setup_status' => $setupStatus,
            'meta' => $this->meta($form),
            'status' => Server::STATUS_PENDING,
        ]);

        ProvisionLinodeServerJob::dispatch($server);
        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

    /**
     * @param  list<string>  $scriptKeys
     */
    private function storeVultr(User $user, Organization $org, ServerCreateForm $form, array $scriptKeys): Server
    {
        Validator::make(
            [
                'name' => $form->name,
                'provider_credential_id' => $form->provider_credential_id,
                'region' => $form->region,
                'size' => $form->size,
                'setup_script_key' => $form->setup_script_key,
            ],
            [
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:100',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]
        )->validate();

        $credential = ProviderCredential::where('organization_id', $org->id)
            ->where('provider', 'vultr')
            ->findOrFail($form->provider_credential_id);

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Vultr,
            'provider_credential_id' => $credential->id,
            'region' => $form->region,
            'size' => $form->size,
            'setup_script_key' => $setupScriptKey,
            'setup_status' => $setupStatus,
            'meta' => $this->meta($form),
            'status' => Server::STATUS_PENDING,
        ]);

        ProvisionVultrServerJob::dispatch($server);
        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

    /**
     * @param  list<string>  $scriptKeys
     */
    private function storeUpcloud(User $user, Organization $org, ServerCreateForm $form, array $scriptKeys): Server
    {
        Validator::make(
            [
                'name' => $form->name,
                'provider_credential_id' => $form->provider_credential_id,
                'region' => $form->region,
                'size' => $form->size,
                'setup_script_key' => $form->setup_script_key,
            ],
            [
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:100',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]
        )->validate();

        $credential = ProviderCredential::where('organization_id', $org->id)
            ->where('provider', 'upcloud')
            ->findOrFail($form->provider_credential_id);

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::UpCloud,
            'provider_credential_id' => $credential->id,
            'region' => $form->region,
            'size' => $form->size,
            'setup_script_key' => $setupScriptKey,
            'setup_status' => $setupStatus,
            'meta' => $this->meta($form),
            'status' => Server::STATUS_PENDING,
        ]);

        ProvisionUpCloudServerJob::dispatch($server);
        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

    /**
     * @param  list<string>  $scriptKeys
     */
    private function storeAzure(User $user, Organization $org, ServerCreateForm $form, array $scriptKeys): Server
    {
        Validator::make(
            [
                'name' => $form->name,
                'provider_credential_id' => $form->provider_credential_id,
                'region' => $form->region,
                'size' => $form->size,
                'setup_script_key' => $form->setup_script_key,
            ],
            [
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:100',
                'size' => 'required|string|max:100',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]
        )->validate();

        $credential = ProviderCredential::where('organization_id', $org->id)
            ->where('provider', 'azure')
            ->findOrFail($form->provider_credential_id);

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Azure,
            'provider_credential_id' => $credential->id,
            'region' => $form->region,
            'size' => $form->size,
            'setup_script_key' => $setupScriptKey,
            'setup_status' => $setupStatus,
            'meta' => $this->meta($form),
            'status' => Server::STATUS_PENDING,
        ]);

        ProvisionAzureServerJob::dispatch($server);
        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

    /**
     * @param  list<string>  $scriptKeys
     */
    private function storeOracle(User $user, Organization $org, ServerCreateForm $form, array $scriptKeys): Server
    {
        Validator::make(
            [
                'name' => $form->name,
                'provider_credential_id' => $form->provider_credential_id,
                'region' => $form->region,
                'size' => $form->size,
                'setup_script_key' => $form->setup_script_key,
            ],
            [
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:100',
                'size' => 'required|string|max:120',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
            ]
        )->validate();

        $credential = ProviderCredential::where('organization_id', $org->id)
            ->where('provider', 'oracle')
            ->findOrFail($form->provider_credential_id);

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Oracle,
            'provider_credential_id' => $credential->id,
            'region' => $form->region,
            'size' => $form->size,
            'setup_script_key' => $setupScriptKey,
            'setup_status' => $setupStatus,
            'meta' => $this->meta($form),
            'status' => Server::STATUS_PENDING,
        ]);

        ProvisionOracleServerJob::dispatch($server);
        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

    private function storeCustom(User $user, Organization $org, ServerCreateForm $form): Server
    {
        Validator::make(
            [
                'name' => $form->name,
                'ip_address' => $form->ip_address,
                'ssh_port' => $form->ssh_port,
                'ssh_user' => $form->ssh_user,
                'ssh_private_key' => $form->ssh_private_key,
                'custom_host_kind' => $form->custom_host_kind,
            ],
            [
                'name' => 'required|string|max:255',
                'ip_address' => 'required|string|max:45',
                'ssh_port' => 'nullable|integer|min:1|max:65535',
                'ssh_user' => 'required|string|max:255',
                'ssh_private_key' => 'required|string',
                'custom_host_kind' => 'required|string|in:vm,docker',
            ]
        )->validate();

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $meta = $this->meta($form);
        $meta['host_kind'] = $form->custom_host_kind === 'docker'
            ? Server::HOST_KIND_DOCKER
            : Server::HOST_KIND_VM;

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Custom,
            'ip_address' => $form->ip_address,
            'ssh_port' => (int) ($form->ssh_port ?: 22),
            'ssh_user' => $form->ssh_user,
            'ssh_private_key' => $form->ssh_private_key,
            'setup_script_key' => $setupScriptKey,
            'setup_status' => $setupStatus,
            'status' => Server::STATUS_READY,
            'meta' => $meta,
        ]);

        audit_log($org, $user, 'server.created', $server);

        $fresh = $server->fresh();
        if ($fresh && RunSetupScriptJob::shouldDispatch($fresh)) {
            WaitForServerSshReadyJob::dispatch($fresh);
        }

        return $server;
    }

    /**
     * Reject incompatible Hetzner (location, server_type) combos before
     * the row is persisted. The catalog filter on the wizard prevents
     * this on the happy path, but a stale form, API hiccup, or direct
     * URL submission can still land here — surfacing a validation error
     * is much friendlier than letting the cloud-side provision job log a
     * generic "unsupported location for server type" half a minute later.
     */
    private function validateHetznerRegionSizeCombo(ProviderCredential $credential, string $region, string $size): void
    {
        $region = trim($region);
        $size = trim($size);
        if ($region === '' || $size === '') {
            return;
        }

        try {
            $svc = new HetznerService($credential);
            $serverTypes = $svc->getServerTypes();
        } catch (Throwable) {
            // Catalog probe failed — fall through and let the provision job
            // surface the API error. We don't want to block submission on a
            // transient Hetzner outage.
            return;
        }

        foreach ($serverTypes as $st) {
            if (! is_array($st) || (string) ($st['name'] ?? '') !== $size) {
                continue;
            }

            $locations = [];
            foreach ((array) ($st['prices'] ?? []) as $price) {
                if (is_array($price) && (string) ($price['location'] ?? '') !== '') {
                    $locations[] = (string) $price['location'];
                }
            }

            if ($locations === [] || in_array($region, $locations, true)) {
                return;
            }

            throw ValidationException::withMessages([
                'size' => __('The server type :size is not available in :region. Available in: :locations.', [
                    'size' => $size,
                    'region' => $region,
                    'locations' => implode(', ', $locations),
                ]),
            ]);
        }
    }
}
