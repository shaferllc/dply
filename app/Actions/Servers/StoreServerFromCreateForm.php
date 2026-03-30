<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Enums\ServerProvider;
use App\Jobs\ProvisionAwsEc2ServerJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionEquinixMetalServerJob;
use App\Jobs\ProvisionFlyIoServerJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Jobs\ProvisionScalewayServerJob;
use App\Jobs\ProvisionUpCloudServerJob;
use App\Jobs\ProvisionVultrServerJob;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Support\ServerProviderGate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Persists a server from the create wizard (cloud API providers or custom SSH).
 */
final class StoreServerFromCreateForm
{
    use AsObject;

    public function handle(User $user, Organization $org, ServerCreateForm $form): Server
    {
        if (! ServerProviderGate::enabled($form->type)) {
            throw ValidationException::withMessages([
                'form.type' => __('This server provider is not available yet.'),
            ]);
        }

        $scriptKeys = array_keys(config('setup_scripts.scripts', []));

        if ($form->type !== 'custom') {
            $hasLinkedCredential = GetProviderCredentialsForServerType::run($org, $form->type)->isNotEmpty();
            Validator::make(
                [
                    'server_role' => $form->server_role,
                    'cache_service' => $form->cache_service,
                    'webserver' => $form->webserver,
                    'php_version' => $form->php_version,
                    'database' => $form->database,
                ],
                ServerProvisionPreferenceRules::rules($form->type, $hasLinkedCredential, $form->server_role)
            )->validate();
        }

        return match ($form->type) {
            'digitalocean' => $this->storeDigitalOcean($user, $org, $form, $scriptKeys),
            'hetzner' => $this->storeHetzner($user, $org, $form, $scriptKeys),
            'linode' => $this->storeLinode($user, $org, $form, $scriptKeys),
            'vultr' => $this->storeVultr($user, $org, $form, $scriptKeys),
            'akamai' => $this->storeAkamai($user, $org, $form, $scriptKeys),
            'scaleway' => $this->storeScaleway($user, $org, $form, $scriptKeys),
            'upcloud' => $this->storeUpcloud($user, $org, $form, $scriptKeys),
            'equinix_metal' => $this->storeEquinixMetal($user, $org, $form, $scriptKeys),
            'fly_io' => $this->storeFlyIo($user, $org, $form, $scriptKeys),
            'aws' => $this->storeAws($user, $org, $form, $scriptKeys),
            'custom' => $this->storeCustom($user, $org, $form),
            default => throw ValidationException::withMessages(['form.type' => __('Invalid server type.')]),
        };
    }

    /**
     * @param  list<string>  $scriptKeys
     */
    private function meta(ServerCreateForm $form): array
    {
        return BuildServerProvisionMeta::run(
            $form->server_role,
            $form->cache_service,
            $form->webserver,
            $form->php_version,
            $form->database,
        );
    }

    /**
     * @param  list<string>  $scriptKeys
     */
    private function storeDigitalOcean(User $user, Organization $org, ServerCreateForm $form, array $scriptKeys): Server
    {
        Validator::make(
            [
                'name' => $form->name,
                'provider_credential_id' => $form->provider_credential_id,
                'region' => $form->region,
                'size' => $form->size,
                'setup_script_key' => $form->setup_script_key,
                'do_vpc_uuid' => $form->do_vpc_uuid,
                'do_tags' => $form->do_tags,
                'do_user_data' => $form->do_user_data,
            ],
            [
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'region' => 'required|string|max:50',
                'size' => 'required|string|max:50',
                'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
                'do_vpc_uuid' => ['nullable', 'string', 'uuid'],
                'do_tags' => ['nullable', 'string', 'max:500'],
                'do_user_data' => ['nullable', 'string', 'max:65536'],
            ]
        )->validate();

        $credential = ProviderCredential::where('organization_id', $org->id)
            ->findOrFail($form->provider_credential_id);

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $doTags = $this->digitalOceanTagsFromForm($form->do_tags);

        $meta = $this->meta($form);
        $meta['digitalocean'] = [
            'ipv6' => $form->do_ipv6,
            'backups' => $form->do_backups,
            'monitoring' => $form->do_monitoring,
            'vpc_uuid' => $form->do_vpc_uuid !== '' ? $form->do_vpc_uuid : null,
            'tags' => $doTags,
            'user_data' => $form->do_user_data,
        ];

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::DigitalOcean,
            'provider_credential_id' => $credential->id,
            'region' => $form->region,
            'size' => $form->size,
            'setup_script_key' => $setupScriptKey,
            'setup_status' => $setupStatus,
            'meta' => $meta,
            'status' => Server::STATUS_PENDING,
        ]);

        ProvisionDigitalOceanDropletJob::dispatch($server);
        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

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

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

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
    private function storeAkamai(User $user, Organization $org, ServerCreateForm $form, array $scriptKeys): Server
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
            ->where('provider', 'akamai')
            ->findOrFail($form->provider_credential_id);

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Akamai,
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
    private function storeScaleway(User $user, Organization $org, ServerCreateForm $form, array $scriptKeys): Server
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
            ->where('provider', 'scaleway')
            ->findOrFail($form->provider_credential_id);

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Scaleway,
            'provider_credential_id' => $credential->id,
            'region' => $form->region,
            'size' => $form->size,
            'setup_script_key' => $setupScriptKey,
            'setup_status' => $setupStatus,
            'meta' => $this->meta($form),
            'status' => Server::STATUS_PENDING,
        ]);

        ProvisionScalewayServerJob::dispatch($server);
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
    private function storeEquinixMetal(User $user, Organization $org, ServerCreateForm $form, array $scriptKeys): Server
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
            ->where('provider', 'equinix_metal')
            ->findOrFail($form->provider_credential_id);

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::EquinixMetal,
            'provider_credential_id' => $credential->id,
            'region' => $form->region,
            'size' => $form->size,
            'setup_script_key' => $setupScriptKey,
            'setup_status' => $setupStatus,
            'meta' => $this->meta($form),
            'status' => Server::STATUS_PENDING,
        ]);

        ProvisionEquinixMetalServerJob::dispatch($server);
        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

    /**
     * @param  list<string>  $scriptKeys
     */
    private function storeFlyIo(User $user, Organization $org, ServerCreateForm $form, array $scriptKeys): Server
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
            ->where('provider', 'fly_io')
            ->findOrFail($form->provider_credential_id);

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::FlyIo,
            'provider_credential_id' => $credential->id,
            'region' => $form->region,
            'size' => $form->size,
            'setup_script_key' => $setupScriptKey,
            'setup_status' => $setupStatus,
            'meta' => $this->meta($form),
            'status' => Server::STATUS_PENDING,
        ]);

        ProvisionFlyIoServerJob::dispatch($server);
        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

    /**
     * @param  list<string>  $scriptKeys
     */
    private function storeAws(User $user, Organization $org, ServerCreateForm $form, array $scriptKeys): Server
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
            ->where('provider', 'aws')
            ->findOrFail($form->provider_credential_id);

        [$setupScriptKey, $setupStatus] = $this->setupScriptState($form->setup_script_key);

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Aws,
            'provider_credential_id' => $credential->id,
            'region' => $form->region,
            'size' => $form->size,
            'setup_script_key' => $setupScriptKey,
            'setup_status' => $setupStatus,
            'meta' => $this->meta($form),
            'status' => Server::STATUS_PENDING,
        ]);

        ProvisionAwsEc2ServerJob::dispatch($server);
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
            ],
            [
                'name' => 'required|string|max:255',
                'ip_address' => 'required|string|max:45',
                'ssh_port' => 'nullable|integer|min:1|max:65535',
                'ssh_user' => 'required|string|max:255',
                'ssh_private_key' => 'required|string',
            ]
        )->validate();

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Custom,
            'ip_address' => $form->ip_address,
            'ssh_port' => (int) ($form->ssh_port ?: 22),
            'ssh_user' => $form->ssh_user,
            'ssh_private_key' => $form->ssh_private_key,
            'status' => Server::STATUS_READY,
        ]);

        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

    /**
     * @return list<string>
     */
    private function digitalOceanTagsFromForm(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        if (count($parts) > 25) {
            throw ValidationException::withMessages([
                'do_tags' => __('At most 25 tags are allowed.'),
            ]);
        }

        $out = [];
        foreach ($parts as $p) {
            $t = trim((string) $p);
            if ($t === '') {
                continue;
            }
            if (strlen($t) > 256) {
                throw ValidationException::withMessages([
                    'do_tags' => __('Each tag must be 256 characters or less.'),
                ]);
            }
            if (! preg_match('/^[a-zA-Z0-9_.:-]+$/', $t)) {
                throw ValidationException::withMessages([
                    'do_tags' => __('Tags may only use letters, numbers, underscores, periods, colons, and hyphens.'),
                ]);
            }
            $out[] = $t;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function setupScriptState(string $setupScriptKey): array
    {
        $key = ! empty(trim($setupScriptKey)) ? $setupScriptKey : null;
        $status = $key ? Server::SETUP_STATUS_PENDING : null;

        return [$key, $status];
    }
}
