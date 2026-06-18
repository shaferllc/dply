<?php

declare(strict_types=1);

namespace App\Actions\Servers\Concerns;

use App\Enums\ServerProvider;
use App\Jobs\PollEksClusterStatusJob;
use App\Jobs\ProvisionAwsEc2ServerJob;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Modules\Cloud\Services\AwsEksService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait StoresAwsServers
{


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
                'do_kubernetes_aws_region' => $form->do_kubernetes_aws_region,
            ],
            [
                'name' => 'required|string|max:255',
                'provider_credential_id' => 'required|exists:provider_credentials,id',
                'do_kubernetes_cluster_name' => 'required|string|max:255',
                'do_kubernetes_namespace' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
                'do_kubernetes_aws_region' => 'required|string',
            ]
        )->validate();

        $credential = ProviderCredential::where('organization_id', $org->id)
            ->where('provider', 'aws')
            ->findOrFail($form->provider_credential_id);

        // The wizard's region picker is the source of truth (the credential's
        // stored region was just the seed value). Fall back to credential region
        // only if the form field was somehow empty.
        $region = trim($form->do_kubernetes_aws_region) !== ''
            ? trim($form->do_kubernetes_aws_region)
            : (string) ($credential->credentials['region'] ?? config('services.aws.default_region', 'us-east-1'));

        // Look up the cluster ARN at register time so the poller has a stable
        // identifier (EKS API takes cluster name + region; ARN is informational
        // but useful for audit + cross-region deduping). Accept registration
        // even if the lookup fails so a transient AWS hiccup doesn't block
        // the user — the poller will retry on its own cadence.
        $clusterArn = null;
        try {
            $service = new AwsEksService($credential, $region);
            $cluster = $service->getCluster(trim($form->do_kubernetes_cluster_name));
            if ($cluster !== null) {
                $clusterArn = isset($cluster['arn']) ? (string) $cluster['arn'] : null;
            }
        } catch (Throwable) {
            //
        }

        $meta = [
            'host_kind' => Server::HOST_KIND_KUBERNETES,
            'kubernetes' => array_filter([
                'provider' => 'aws',
                'cluster_name' => trim($form->do_kubernetes_cluster_name),
                'cluster_id' => $clusterArn,
                'namespace' => trim($form->do_kubernetes_namespace) !== '' ? trim($form->do_kubernetes_namespace) : 'default',
                'region' => $region,
                'provisioned_by_dply' => null, // explicit: never provisioned by dply for EKS
            ], static fn ($v): bool => $v !== null),
        ];

        $server = $user->servers()->create([
            'organization_id' => $org->id,
            'name' => $form->name,
            'provider' => ServerProvider::Aws,
            'provider_credential_id' => $credential->id,
            'region' => $region,
            'ssh_port' => 22,
            'ssh_user' => 'kubernetes',
            // Existing cluster — assume READY. The poller will flip to ERROR
            // if DescribeCluster returns a non-ACTIVE status on first hit.
            'status' => Server::STATUS_READY,
            'health_status' => Server::HEALTH_REACHABLE,
            'meta' => $meta,
        ]);

        audit_log($org, $user, 'server.created', $server);

        // One-shot hydration: poller fetches DescribeCluster + nodegroups +
        // generates kubeconfig. Stops on first poll since cluster is ACTIVE
        // (or marks ERROR if not). Same job runs on the Refresh button later.
        PollEksClusterStatusJob::dispatch($server);

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
}
