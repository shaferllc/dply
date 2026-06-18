<?php

declare(strict_types=1);

namespace App\Actions\Servers\Concerns;

use App\Enums\ServerProvider;
use App\Jobs\PollDoksClusterStatusJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Modules\Cloud\Services\DigitalOceanService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait StoresDigitalOceanServers
{


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
            // server card surfaces that state. PollDoksClusterStatusJob flips
            // it to READY when DO reports status.state == "running" (and also
            // fetches kubeconfig at that moment).
            $serverStatus = Server::STATUS_PROVISIONING;
            $health = Server::HEALTH_UNREACHABLE;
        } else {
            // Registered-existing mode: look up the cluster ID by name so the
            // poller has something to fetch with. We accept registration even
            // if the lookup fails (DO blip, account permissions) — the cluster
            // page surfaces the missing-id state and offers a "Try again".
            try {
                foreach ((new DigitalOceanService($credential))->getKubernetesClusters() as $cluster) {
                    if (is_array($cluster) && (string) ($cluster['name'] ?? '') === $clusterName) {
                        $clusterId = (string) ($cluster['id'] ?? '');
                        $clusterRegion = (string) ($cluster['region'] ?? '');
                        break;
                    }
                }
            } catch (Throwable) {
                //
            }
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
            // clusters live in PROVISIONING until the poller flips them.
            'status' => $serverStatus,
            'health_status' => $health,
            'meta' => $meta,
        ]);

        audit_log($org, $user, 'server.created', $server);

        // Dispatch the poller for both paths: created clusters need recurring
        // checks until state=running; registered clusters get a single-shot
        // hydration (the poller will see state=running on poll #1, grab the
        // kubeconfig, and stop). Skipped when cluster_id couldn't be looked up
        // — the cluster page will surface a "missing id, try again" affordance.
        if ($clusterId !== null && $clusterId !== '') {
            PollDoksClusterStatusJob::dispatch($server);
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
}
