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
use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Services\DigitalOceanService;
use App\Support\ServerProviderGate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

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

        if ($form->type === 'custom') {
            $hasLinkedCredential = GetProviderCredentialsForServerType::run($org, $form->type)->isNotEmpty();
            $installProfileIds = collect(config('server_provision_options.install_profiles', []))->pluck('id')->filter()->values()->all();
            Validator::make(
                [
                    'install_profile' => $form->install_profile,
                    'server_role' => $form->server_role,
                    'cache_service' => $form->cache_service,
                    'webserver' => $form->webserver,
                    'php_version' => $form->php_version,
                    'database' => $form->database,
                    'setup_script_key' => $form->setup_script_key,
                ],
                array_merge(
                    [
                        'install_profile' => ['required', 'string', Rule::in($installProfileIds)],
                        'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
                    ],
                    ServerProvisionPreferenceRules::rules('custom', $hasLinkedCredential, $form->server_role)
                )
            )->validate();
        }

        if (! in_array($form->type, ['custom', 'digitalocean_functions', 'digitalocean_kubernetes', 'aws_kubernetes', 'aws_lambda'], true)) {
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
            'digitalocean_functions' => $this->storeDigitalOceanFunctions($user, $org, $form),
            'digitalocean_kubernetes' => $this->storeDigitalOceanKubernetes($user, $org, $form),
            'aws_kubernetes' => $this->storeAwsKubernetes($user, $org, $form),
            'aws_lambda' => $this->storeAwsLambda($user, $org, $form),
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
        $meta = BuildServerProvisionMeta::run(
            $form->install_profile,
            $form->server_role,
            $form->cache_service,
            $form->webserver,
            $form->php_version,
            $form->database,
            [
                'ruby' => $form->ruby_version,
                'node' => $form->node_version,
                'python' => $form->python_version,
                'go' => $form->go_version,
            ],
        );

        // Provider-mode Docker hosts: tag the meta so Server::hostKind() returns
        // HOST_KIND_DOCKER. Custom-mode does this inline in its own branch.
        if ($form->mode === 'provider' && $form->provider_host_kind === 'docker') {
            $meta['host_kind'] = Server::HOST_KIND_DOCKER;
        }

        return $meta;
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

    private function storeDigitalOceanFunctions(User $user, Organization $org, ServerCreateForm $form): Server
    {
        Validator::make(
            [
                'name' => $form->name,
                'provider_credential_id' => $form->provider_credential_id,
                'do_functions_api_host' => $form->do_functions_api_host,
                'do_functions_namespace' => $form->do_functions_namespace,
                'do_functions_access_key' => $form->do_functions_access_key,
            ],
            [
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'do_functions_api_host' => 'required|url|max:255',
                'do_functions_namespace' => 'required|string|max:255',
                'do_functions_access_key' => ['required', 'string', 'max:500', 'regex:/^.+:.+$/'],
            ],
            [
                'do_functions_access_key.regex' => __('Use the DigitalOcean Functions access key format `id:secret`.'),
            ]
        )->validate();

        $credential = ProviderCredential::where('organization_id', $org->id)
            ->where('provider', 'digitalocean')
            ->findOrFail($form->provider_credential_id);

        $meta = [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => [
                'api_host' => rtrim($form->do_functions_api_host, '/'),
                'namespace' => trim($form->do_functions_namespace),
                'access_key' => trim($form->do_functions_access_key),
            ],
        ];

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::DigitalOcean,
            'provider_credential_id' => $credential->id,
            'ssh_port' => 22,
            'ssh_user' => 'functions',
            'status' => Server::STATUS_READY,
            'health_status' => Server::HEALTH_REACHABLE,
            'meta' => $meta,
        ]);

        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

    private function storeDigitalOceanKubernetes(User $user, Organization $org, ServerCreateForm $form): Server
    {
        $isCreatingNew = $form->do_kubernetes_source === 'new';

        $payload = [
            'name' => $form->name,
            'provider_credential_id' => $form->provider_credential_id,
            'do_kubernetes_namespace' => $form->do_kubernetes_namespace,
        ];
        $rules = [
            'name' => 'required|string|max:255',
            'provider_credential_id' => 'required|exists:provider_credentials,id',
            'do_kubernetes_namespace' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
        ];
        if ($isCreatingNew) {
            $payload['do_kubernetes_new_name'] = $form->do_kubernetes_new_name;
            $payload['do_kubernetes_new_region'] = $form->do_kubernetes_new_region;
            $payload['do_kubernetes_new_node_size'] = $form->do_kubernetes_new_node_size;
            $payload['do_kubernetes_new_node_count'] = $form->do_kubernetes_new_node_count;
            $rules['do_kubernetes_new_name'] = ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z]([-a-z0-9]*[a-z0-9])?$/'];
            $rules['do_kubernetes_new_region'] = ['required', 'string'];
            $rules['do_kubernetes_new_node_size'] = ['required', 'string'];
            $rules['do_kubernetes_new_node_count'] = ['required', 'integer', 'min:1', 'max:20'];
        } else {
            $payload['do_kubernetes_cluster_name'] = $form->do_kubernetes_cluster_name;
            $rules['do_kubernetes_cluster_name'] = 'required|string|max:255';
        }
        Validator::make($payload, $rules)->validate();

        $credential = ProviderCredential::where('organization_id', $org->id)
            ->where('provider', 'digitalocean')
            ->findOrFail($form->provider_credential_id);

        $clusterName = trim($form->do_kubernetes_cluster_name);
        $clusterRegion = '';
        $clusterId = null;
        $serverStatus = Server::STATUS_READY;
        $health = Server::HEALTH_REACHABLE;

        if ($isCreatingNew) {
            try {
                $created = (new DigitalOceanService($credential))->createKubernetesCluster(
                    name: trim($form->do_kubernetes_new_name),
                    region: trim($form->do_kubernetes_new_region),
                    nodeSize: trim($form->do_kubernetes_new_node_size),
                    nodeCount: (int) $form->do_kubernetes_new_node_count,
                    ha: (bool) $form->do_kubernetes_new_ha,
                    version: $form->do_kubernetes_new_version !== '' ? $form->do_kubernetes_new_version : null,
                );
            } catch (Throwable $e) {
                throw ValidationException::withMessages([
                    'form.do_kubernetes_new_name' => __('DigitalOcean refused to create the cluster: :detail', ['detail' => $e->getMessage()]),
                ]);
            }

            $clusterName = (string) ($created['name'] ?? $form->do_kubernetes_new_name);
            $clusterRegion = (string) ($created['region'] ?? $form->do_kubernetes_new_region);
            $clusterId = isset($created['id']) ? (string) $created['id'] : null;
            // Cluster is "provisioning" at DO until node pool VMs come up; the
            // server card surfaces that state so the user knows deploys can't
            // hit it yet. A future poller will flip it to READY when DO reports
            // status.state == "running".
            $serverStatus = Server::STATUS_PROVISIONING;
            $health = Server::HEALTH_UNREACHABLE;
        }

        $meta = [
            'host_kind' => Server::HOST_KIND_KUBERNETES,
            'kubernetes' => array_filter([
                'provider' => 'digitalocean',
                'cluster_name' => $clusterName,
                'cluster_id' => $clusterId,
                'region' => $clusterRegion !== '' ? $clusterRegion : null,
                'namespace' => trim($form->do_kubernetes_namespace) !== '' ? trim($form->do_kubernetes_namespace) : 'default',
                'provisioned_by_dply' => $isCreatingNew ? true : null,
            ], static fn ($v): bool => $v !== null),
        ];

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::DigitalOcean,
            'provider_credential_id' => $credential->id,
            'ssh_port' => 22,
            'ssh_user' => 'kubernetes',
            // Existing-cluster registrations are READY immediately; created
            // clusters live in PROVISIONING until DO finishes spinning up the
            // node pool.
            'status' => $serverStatus,
            'health_status' => $health,
            'meta' => $meta,
        ]);

        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

    /**
     * Register an existing AWS EKS cluster as a dply server. Mirrors the DOKS path —
     * cluster + namespace come from the wizard form (do_kubernetes_* fields are
     * provider-agnostic at the form layer despite the prefix; renaming is churn).
     */
    private function storeAwsKubernetes(User $user, Organization $org, ServerCreateForm $form): Server
    {
        Validator::make(
            [
                'name' => $form->name,
                'provider_credential_id' => $form->provider_credential_id,
                'do_kubernetes_cluster_name' => $form->do_kubernetes_cluster_name,
                'do_kubernetes_namespace' => $form->do_kubernetes_namespace,
            ],
            [
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'do_kubernetes_cluster_name' => 'required|string|max:255',
                'do_kubernetes_namespace' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
            ]
        )->validate();

        $credential = ProviderCredential::where('organization_id', $org->id)
            ->where('provider', 'aws')
            ->findOrFail($form->provider_credential_id);

        $region = (string) ($credential->credentials['region'] ?? config('services.aws.default_region', 'us-east-1'));

        $meta = [
            'host_kind' => Server::HOST_KIND_KUBERNETES,
            'kubernetes' => [
                'provider' => 'aws',
                'cluster_name' => trim($form->do_kubernetes_cluster_name),
                'namespace' => trim($form->do_kubernetes_namespace) !== '' ? trim($form->do_kubernetes_namespace) : 'default',
                'region' => $region,
            ],
        ];

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Aws,
            'provider_credential_id' => $credential->id,
            'region' => $region,
            'ssh_port' => 22,
            'ssh_user' => 'kubernetes',
            'status' => Server::STATUS_READY,
            'health_status' => Server::HEALTH_REACHABLE,
            'meta' => $meta,
        ]);

        audit_log($org, $user, 'server.created', $server);

        return $server;
    }

    private function storeAwsLambda(User $user, Organization $org, ServerCreateForm $form): Server
    {
        Validator::make(
            [
                'name' => $form->name,
                'provider_credential_id' => $form->provider_credential_id,
                'aws_lambda_region' => $form->aws_lambda_region,
            ],
            [
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'aws_lambda_region' => 'required|string|max:255',
            ]
        )->validate();

        $credential = ProviderCredential::where('organization_id', $org->id)
            ->where('provider', 'aws')
            ->findOrFail($form->provider_credential_id);

        $meta = [
            'host_kind' => Server::HOST_KIND_AWS_LAMBDA,
            'aws_lambda' => [
                'region' => trim($form->aws_lambda_region),
            ],
        ];

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Aws,
            'provider_credential_id' => $credential->id,
            'ssh_port' => 22,
            'ssh_user' => 'lambda',
            'region' => trim($form->aws_lambda_region),
            'status' => Server::STATUS_READY,
            'health_status' => Server::HEALTH_REACHABLE,
            'meta' => $meta,
        ]);

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
