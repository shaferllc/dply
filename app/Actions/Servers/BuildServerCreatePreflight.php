<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Livewire\Forms\ServerCreateForm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BuildServerCreatePreflight
{
    use AsObject;

    /**
     * @param  array{
     *     credentials: Collection<int, mixed>,
     *     regions: list<array<string, mixed>>,
     *     sizes: list<array<string, mixed>>,
     *     region_label: string,
     *     size_label: string
     * }  $catalog
     * @param  array{
     *     server_roles: list<array<string, mixed>>,
     *     cache_services: list<array<string, mixed>>,
     *     webservers: list<array<string, mixed>>,
     *     php_versions: list<array<string, mixed>>,
     *     databases: list<array<string, mixed>>
     * }  $provisionOptions
     * @return array{
     *     status: 'ready'|'warning'|'blocked',
     *     can_submit: bool,
     *     groups: array<string, list<array{
     *         key: string,
     *         severity: 'error'|'warning'|'info',
     *         label: string,
     *         detail: string,
     *         blocking: bool,
     *         field: ?string
     *     }>>,
     *     checks: list<array{
     *         key: string,
     *         severity: 'error'|'warning'|'info',
     *         label: string,
     *         detail: string,
     *         blocking: bool,
     *         field: ?string
     *     }>,
     *     blocking_fields: array<string, string>,
     *     blocking_count: int,
     *     warning_count: int,
     *     summary: string,
     *     provider_health: array<string, mixed>|null,
     *     cost_preview: array{
     *         state: 'available'|'unavailable'|'incomplete',
     *         provider: string,
     *         region: ?string,
     *         size: ?string,
     *         price_monthly: ?float,
     *         price_hourly: ?float,
     *         formatted_price: ?string,
     *         source: ?string,
     *         detail: string
     *     }
     * }
     */
    public function handle(
        ServerCreateForm $form,
        array $catalog,
        array $provisionOptions,
        bool $canCreateServer,
        bool $hasUserSshKeys,
        bool $hasProvisionableUserSshKeys,
        bool $hasAnyProviderCredentials,
        bool $hasLinkedCredential,
        ?array $providerHealth = null,
        array $customConnectionTest = [],
        array $sizeRecommendations = [],
        ?int $currentStep = null,
    ): array {
        // When we don't know the step, assume Review — that's the safe default
        // because Review is where "blocking" actually gates submission.
        $stepForChecks = $currentStep ?? 4;
        $checks = match ($form->type) {
            'custom' => $this->customChecks($form, $canCreateServer, $hasUserSshKeys, $hasProvisionableUserSshKeys, $customConnectionTest, $hasLinkedCredential),
            'digitalocean_functions' => $this->digitalOceanFunctionsChecks($form, $catalog, $canCreateServer, $hasAnyProviderCredentials, $hasLinkedCredential, $providerHealth),
            'digitalocean_kubernetes' => $this->digitalOceanKubernetesChecks($form, $catalog, $canCreateServer, $hasAnyProviderCredentials, $hasLinkedCredential, $providerHealth, $stepForChecks),
            'aws_kubernetes' => $this->digitalOceanKubernetesChecks($form, $catalog, $canCreateServer, $hasAnyProviderCredentials, $hasLinkedCredential, $providerHealth, $stepForChecks),
            'aws_lambda' => $this->awsLambdaChecks($form, $catalog, $canCreateServer, $hasAnyProviderCredentials, $hasLinkedCredential, $providerHealth),
            default => $this->cloudChecks($form, $catalog, $provisionOptions, $canCreateServer, $hasUserSshKeys, $hasProvisionableUserSshKeys, $hasAnyProviderCredentials, $hasLinkedCredential, $providerHealth, $sizeRecommendations),
        };

        $blockingFields = [];
        foreach ($checks as $check) {
            if ($check['blocking'] && is_string($check['field']) && $check['field'] !== '') {
                $blockingFields[$check['field']] = $check['detail'];
            }
        }

        $blockingCount = count(array_filter($checks, fn (array $check): bool => $check['blocking']));
        $warningCount = count(array_filter($checks, fn (array $check): bool => $check['severity'] === 'warning'));

        return [
            'status' => $blockingCount > 0 ? 'blocked' : ($warningCount > 0 ? 'warning' : 'ready'),
            'can_submit' => $blockingCount === 0,
            'groups' => $this->groupChecks($checks, $form->type),
            'checks' => $checks,
            'blocking_fields' => $blockingFields,
            'blocking_count' => $blockingCount,
            'warning_count' => $warningCount,
            'summary' => $blockingCount > 0
                ? __('Fix the blocking issues before you create this server.')
                : ($warningCount > 0
                    ? __('You can continue, but review the warnings first.')
                    : __('Everything needed to create this server looks ready.')),
            'provider_health' => $providerHealth,
            'cost_preview' => $this->buildCostPreview($form, $catalog),
        ];
    }

    /**
     * @return list<array{key:string,severity:'error'|'warning'|'info',label:string,detail:string,blocking:bool,field:?string}>
     */
    private function cloudChecks(
        ServerCreateForm $form,
        array $catalog,
        array $provisionOptions,
        bool $canCreateServer,
        bool $hasUserSshKeys,
        bool $hasProvisionableUserSshKeys,
        bool $hasAnyProviderCredentials,
        bool $hasLinkedCredential,
        ?array $providerHealth,
        array $sizeRecommendations,
    ): array {
        $checks = [];

        if (! $canCreateServer) {
            $checks[] = $this->check('server_limit', 'error', __('Server limit reached'), __('Your organization cannot create another server on the current plan.'), true);
        }

        if (! $hasUserSshKeys) {
            $checks[] = $this->check(
                'user_ssh_keys',
                'error',
                __('Add a personal profile SSH key'),
                __('Add at least one personal SSH public key in your profile before provisioning a server so Dply can place your access on the machine during setup.'),
                true
            );
        } elseif (! $hasProvisionableUserSshKeys) {
            $checks[] = $this->check(
                'user_ssh_key_defaults',
                'warning',
                __('No personal SSH key is set for new servers'),
                __('This server will be created without one of your saved personal SSH keys unless you attach one after setup or mark a profile key for new servers.'),
                false
            );
        }

        if (! $hasAnyProviderCredentials) {
            $checks[] = $this->check('provider_credentials', 'error', __('Add a provider credential'), __('Add a server provider credential before creating a cloud server.'), true, 'provider_credential_id');

            return $checks;
        }

        if (! $hasLinkedCredential || $catalog['credentials']->isEmpty()) {
            $checks[] = $this->check('provider_credentials', 'error', __('No linked account for this provider'), __('Add or select a credential for the chosen provider before continuing.'), true, 'provider_credential_id');
        } elseif ($form->provider_credential_id === '') {
            $checks[] = $this->check('provider_credential_id', 'error', __('Choose an account'), __('Select the provider account that should create this server.'), true, 'provider_credential_id');
        } elseif (! $catalog['credentials']->contains('id', $form->provider_credential_id)) {
            $checks[] = $this->check('provider_credential_id', 'error', __('Selected account is unavailable'), __('The chosen provider credential is not available for this server type.'), true, 'provider_credential_id');
        } else {
            $checks[] = $this->check('provider_credential_id', 'info', __('Account selected'), __('The selected provider credential is ready for this request.'), false);
        }

        if ($providerHealth !== null) {
            $checks[] = $this->check(
                'provider_health',
                $providerHealth['severity'],
                $providerHealth['label'],
                $providerHealth['detail'],
                in_array($providerHealth['status'], ['invalid', 'expired', 'under_scoped', 'misconfigured'], true),
                'provider_credential_id',
            );
        }

        $checks = [...$checks, ...$this->selectionChecks(
            value: $form->region,
            options: $catalog['regions'] ?? [],
            field: 'region',
            label: (string) ($catalog['region_label'] ?? __('Region')),
            missingDetail: __('Choose a region before creating the server.'),
            unavailableDetail: __('The selected region is not available for the current catalog response.')
        )];

        $checks = [...$checks, ...$this->selectionChecks(
            value: $form->size,
            options: $catalog['sizes'] ?? [],
            field: 'size',
            label: (string) ($catalog['size_label'] ?? __('Plan / size')),
            missingDetail: __('Choose a size before creating the server.'),
            unavailableDetail: __('The selected size is not available for the current catalog response.')
        )];

        try {
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

            $checks[] = $this->check('stack', 'info', __('Stack selections look valid'), __('The selected role and software options are compatible.'), false);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $checks[] = $this->check(
                    'stack_'.$field,
                    'error',
                    __('Invalid stack selection'),
                    (string) ($messages[0] ?? __('This selection is not valid for the chosen server role.')),
                    true,
                    $field
                );
            }
        }

        if (($catalog['regions'] ?? []) === [] && $form->provider_credential_id !== '') {
            $checks[] = $this->check('regions_unverified', 'warning', __('Region availability could not be verified'), __('The provider catalog did not return regions just now. You can continue if you trust the current selection.'), false, 'region');
        }

        if (($catalog['sizes'] ?? []) === [] && $form->provider_credential_id !== '') {
            $checks[] = $this->check('sizes_unverified', 'warning', __('Size availability could not be verified'), __('The provider catalog did not return plan or size data just now. You can continue if you trust the current selection.'), false, 'size');
        }

        $sizeValue = $form->size;
        if ($sizeValue !== '' && isset($sizeRecommendations[$sizeValue])) {
            $recommendation = $sizeRecommendations[$sizeValue];
            $role = collect(config('server_provision_options.server_roles', []))
                ->firstWhere('id', $form->server_role);
            $roleLabel = is_array($role) && filled($role['label'] ?? null)
                ? (string) $role['label']
                : str($form->server_role)->replace('_', ' ')->title()->toString();
            // A too-small box for a memory-heavy role isn't just suboptimal —
            // the provision itself can OOM mid-install (cloud-init/apt getting
            // killed on sub-1GB droplets), which leaves a half-built server and
            // a wedged journey. For application/database roles, promote a
            // too-small plan from a soft warning to a hard block so the user
            // picks at least the role's minimum (≈2GB) before we provision.
            $tooSmall = $recommendation['state'] === 'too_small';
            $memoryHeavyRole = in_array($form->server_role, ['application', 'database'], true);
            $blockSize = $tooSmall && $memoryHeavyRole;

            $checks[] = $this->check(
                'size_recommendation',
                $blockSize ? 'error' : ($tooSmall ? 'warning' : 'info'),
                $blockSize
                    ? __('Size too small for :role', ['role' => $roleLabel])
                    : __('Sizing for :role', ['role' => $roleLabel]),
                $blockSize
                    ? $recommendation['detail'].' '.__('This plan is too small to provision reliably — setup can run out of memory mid-install. Choose a plan with at least 2 GB RAM.')
                    : $recommendation['detail'],
                $blockSize,
                'size',
            );
        }

        return $checks;
    }

    /**
     * @return list<array{key:string,severity:'error'|'warning'|'info',label:string,detail:string,blocking:bool,field:?string}>
     */
    private function customChecks(ServerCreateForm $form, bool $canCreateServer, bool $hasUserSshKeys, bool $hasProvisionableUserSshKeys, array $customConnectionTest, bool $hasLinkedCredential): array
    {
        $checks = [];

        if (! $canCreateServer) {
            $checks[] = $this->check('server_limit', 'error', __('Server limit reached'), __('Your organization cannot create another server on the current plan.'), true);
        }

        try {
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

            $checks[] = $this->check('custom_connection', 'info', __('Connection details provided'), __('The required connection fields are present.'), false);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $checks[] = $this->check(
                    'custom_'.$field,
                    'error',
                    __('Missing required connection details'),
                    (string) ($messages[0] ?? __('This field is required.')),
                    true,
                    $field
                );
            }
        }

        $scriptKeys = array_keys(config('setup_scripts.scripts', []));
        $installProfileIds = collect(config('server_provision_options.install_profiles', []))->pluck('id')->filter()->values()->all();

        try {
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

            $checks[] = $this->check('custom_stack', 'info', __('Stack selection ready'), __('Install profile and default stack choices are valid for this BYO server.'), false);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $checks[] = $this->check(
                    'custom_stack_'.$field,
                    'error',
                    __('Invalid stack configuration'),
                    (string) ($messages[0] ?? __('Invalid value.')),
                    true,
                    $field
                );
            }
        }

        if (($customConnectionTest['matches_current_form'] ?? false) && ($customConnectionTest['state'] ?? 'idle') === 'success') {
            $checks[] = $this->check(
                'custom_verification',
                'info',
                __('SSH connection verified'),
                (string) ($customConnectionTest['message'] ?? __('SSH access was verified for the current form values.')),
                false
            );
        } elseif (($customConnectionTest['matches_current_form'] ?? false) && in_array(($customConnectionTest['state'] ?? 'idle'), ['warning', 'error'], true)) {
            $checks[] = $this->check(
                'custom_verification',
                (string) (($customConnectionTest['state'] ?? 'warning') === 'error' ? 'error' : 'warning'),
                __('SSH test failed'),
                (string) ($customConnectionTest['message'] ?? __('The most recent SSH test failed for these connection details.')),
                false
            );
        } else {
            $checks[] = $this->check(
                'custom_verification',
                'warning',
                __('SSH reachability is not verified yet'),
                __('Run Test connection to verify the host, username, and private key before saving this custom server.'),
                false
            );
        }

        return $checks;
    }

    /**
     * @return list<array{key:string,severity:'error'|'warning'|'info',label:string,detail:string,blocking:bool,field:?string}>
     */
    private function digitalOceanFunctionsChecks(
        ServerCreateForm $form,
        array $catalog,
        bool $canCreateServer,
        bool $hasAnyProviderCredentials,
        bool $hasLinkedCredential,
        ?array $providerHealth,
    ): array {
        $checks = [];

        if (! $canCreateServer) {
            $checks[] = $this->check('server_limit', 'error', __('Server limit reached'), __('Your organization cannot create another server on the current plan.'), true);
        }

        if (! $hasAnyProviderCredentials) {
            $checks[] = $this->check('provider_credentials', 'error', __('Add a provider credential'), __('Add a DigitalOcean credential before creating a Functions host.'), true, 'provider_credential_id');

            return $checks;
        }

        if (! $hasLinkedCredential || $catalog['credentials']->isEmpty()) {
            $checks[] = $this->check('provider_credentials', 'error', __('No linked DigitalOcean account'), __('Add or select a DigitalOcean credential before continuing.'), true, 'provider_credential_id');
        } elseif ($form->provider_credential_id === '') {
            $checks[] = $this->check('provider_credential_id', 'error', __('Choose an account'), __('Select the DigitalOcean account that should manage this Functions host.'), true, 'provider_credential_id');
        } elseif (! $catalog['credentials']->contains('id', $form->provider_credential_id)) {
            $checks[] = $this->check('provider_credential_id', 'error', __('Selected account is unavailable'), __('The chosen DigitalOcean credential is not available.'), true, 'provider_credential_id');
        } else {
            $checks[] = $this->check('provider_credential_id', 'info', __('Account selected'), __('The selected DigitalOcean credential is ready for this request.'), false);
        }

        if ($providerHealth !== null) {
            $checks[] = $this->check(
                'provider_health',
                $providerHealth['severity'],
                $providerHealth['label'],
                $providerHealth['detail'],
                in_array($providerHealth['status'], ['invalid', 'expired', 'under_scoped', 'misconfigured'], true),
                'provider_credential_id',
            );
        }

        try {
            Validator::make(
                [
                    'name' => $form->name,
                    'do_functions_api_host' => $form->do_functions_api_host,
                    'do_functions_namespace' => $form->do_functions_namespace,
                    'do_functions_access_key' => $form->do_functions_access_key,
                ],
                [
                    'name' => 'required|string|max:255',
                    'do_functions_api_host' => 'required|url|max:255',
                    'do_functions_namespace' => 'required|string|max:255',
                    'do_functions_access_key' => ['required', 'string', 'max:500', 'regex:/^.+:.+$/'],
                ]
            )->validate();

            $checks[] = $this->check('functions_config', 'info', __('Functions host settings look valid'), __('The namespace, API host, and access key are present.'), false);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $checks[] = $this->check(
                    'functions_'.$field,
                    'error',
                    __('Missing required Functions host details'),
                    (string) ($messages[0] ?? __('This field is required.')),
                    true,
                    $field
                );
            }
        }

        $checks[] = $this->check(
            'functions_runtime',
            'warning',
            __('Functions hosts use a different deploy path'),
            __('This target skips SSH, package installs, nginx, firewall, cron, and server health checks. Site deploys must use the Functions runtime path instead.'),
            false
        );

        return $checks;
    }

    /**
     * @return list<array{key:string,severity:'error'|'warning'|'info',label:string,detail:string,blocking:bool,field:?string}>
     */
    private function digitalOceanKubernetesChecks(
        ServerCreateForm $form,
        array $catalog,
        bool $canCreateServer,
        bool $hasAnyProviderCredentials,
        bool $hasLinkedCredential,
        ?array $providerHealth,
        int $currentStep = 4,
    ): array {
        // Cluster name + create-new fields don't exist on the form until the
        // user lands on StepWhat (step 3). Showing "BLOCKED — missing cluster
        // name" on StepWhere (step 2) is just noise — the user hasn't reached
        // the picker yet. Downgrade those specific checks to warnings on the
        // pre-review steps; StepWhat::next() and the StoreServerFromCreateForm
        // validator both still enforce the constraint at submit time.
        $clusterFieldsBlock = $currentStep >= 4;
        $checks = [];

        if (! $canCreateServer) {
            $checks[] = $this->check('server_limit', 'error', __('Server limit reached'), __('Your organization cannot create another server on the current plan.'), true);
        }

        if (! $hasAnyProviderCredentials) {
            $checks[] = $this->check('provider_credentials', 'error', __('Add a provider credential'), __('Add a DigitalOcean credential before creating a Kubernetes cluster target.'), true, 'provider_credential_id');

            return $checks;
        }

        if (! $hasLinkedCredential || $catalog['credentials']->isEmpty()) {
            $checks[] = $this->check('provider_credentials', 'error', __('No linked DigitalOcean account'), __('Add or select a DigitalOcean credential before continuing.'), true, 'provider_credential_id');
        } elseif ($form->provider_credential_id === '') {
            $checks[] = $this->check('provider_credential_id', 'error', __('Choose an account'), __('Select the DigitalOcean account that should manage this Kubernetes target.'), true, 'provider_credential_id');
        } elseif (! $catalog['credentials']->contains('id', $form->provider_credential_id)) {
            $checks[] = $this->check('provider_credential_id', 'error', __('Selected account is unavailable'), __('The chosen DigitalOcean credential is not available.'), true, 'provider_credential_id');
        } else {
            $checks[] = $this->check('provider_credential_id', 'info', __('Account selected'), __('The selected DigitalOcean credential is ready for this request.'), false);
        }

        if ($providerHealth !== null) {
            $checks[] = $this->check(
                'provider_health',
                $providerHealth['severity'],
                $providerHealth['label'],
                $providerHealth['detail'],
                in_array($providerHealth['status'], ['invalid', 'expired', 'under_scoped', 'misconfigured'], true),
                'provider_credential_id',
            );
        }

        $isCreatingNewDoks = $form->type === 'digitalocean_kubernetes' && $form->do_kubernetes_source === 'new';
        try {
            $payload = [
                'name' => $form->name,
                'do_kubernetes_namespace' => $form->do_kubernetes_namespace,
            ];
            $rules = [
                'name' => 'required|string|max:255',
                'do_kubernetes_namespace' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
            ];
            if ($isCreatingNewDoks) {
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

            $checks[] = $this->check('kubernetes_config', 'info', __('Kubernetes target settings look valid'), __('The cluster details and namespace are present.'), false);
        } catch (ValidationException $e) {
            // Fields touched on StepWhat (cluster name / create-new spec) only
            // block at Review. The `name` and `namespace` fields are entered on
            // earlier steps so they keep their normal blocking severity.
            $clusterFieldNames = [
                'do_kubernetes_cluster_name',
                'do_kubernetes_new_name',
                'do_kubernetes_new_region',
                'do_kubernetes_new_node_size',
                'do_kubernetes_new_node_count',
            ];
            foreach ($e->errors() as $field => $messages) {
                $isClusterField = in_array($field, $clusterFieldNames, true);
                $isBlocking = $isClusterField ? $clusterFieldsBlock : true;
                $severity = $isBlocking ? 'error' : 'warning';
                $label = $isClusterField && ! $clusterFieldsBlock
                    ? __('Cluster details pending')
                    : __('Missing required Kubernetes target details');
                $detail = $isClusterField && ! $clusterFieldsBlock
                    ? __('You\'ll fill these in on the next step.')
                    : (string) ($messages[0] ?? __('This field is required.'));
                $checks[] = $this->check(
                    'kubernetes_'.$field,
                    $severity,
                    $label,
                    $detail,
                    $isBlocking,
                    $field
                );
            }
        }

        $checks[] = $this->check(
            'kubernetes_runtime',
            'warning',
            __('Kubernetes targets use a cluster-native deploy path'),
            __('This target skips SSH, package installs, nginx, firewall, cron, and server health checks. Site deploys render Kubernetes manifests and runtime artifacts instead.'),
            false
        );

        return $checks;
    }

    /**
     * @return list<array{key:string,severity:'error'|'warning'|'info',label:string,detail:string,blocking:bool,field:?string}>
     */
    private function awsLambdaChecks(
        ServerCreateForm $form,
        array $catalog,
        bool $canCreateServer,
        bool $hasAnyProviderCredentials,
        bool $hasLinkedCredential,
        ?array $providerHealth,
    ): array {
        $checks = [];

        if (! $canCreateServer) {
            $checks[] = $this->check('server_limit', 'error', __('Server limit reached'), __('Your organization cannot create another server on the current plan.'), true);
        }

        if (! $hasAnyProviderCredentials) {
            $checks[] = $this->check('provider_credentials', 'error', __('Add an AWS credential'), __('Add an AWS credential before creating a Lambda target.'), true, 'provider_credential_id');

            return $checks;
        }

        if (! $hasLinkedCredential || $catalog['credentials']->isEmpty()) {
            $checks[] = $this->check('provider_credentials', 'error', __('No linked AWS account'), __('Add or select an AWS credential before continuing.'), true, 'provider_credential_id');
        } elseif ($form->provider_credential_id === '') {
            $checks[] = $this->check('provider_credential_id', 'error', __('Choose an account'), __('Select the AWS account that should manage this Lambda target.'), true, 'provider_credential_id');
        } elseif (! $catalog['credentials']->contains('id', $form->provider_credential_id)) {
            $checks[] = $this->check('provider_credential_id', 'error', __('Selected account is unavailable'), __('The chosen AWS credential is not available.'), true, 'provider_credential_id');
        } else {
            $checks[] = $this->check('provider_credential_id', 'info', __('Account selected'), __('The selected AWS credential is ready for this request.'), false);
        }

        if ($providerHealth !== null) {
            $checks[] = $this->check(
                'provider_health',
                $providerHealth['severity'],
                $providerHealth['label'],
                $providerHealth['detail'],
                in_array($providerHealth['status'], ['invalid', 'expired', 'under_scoped', 'misconfigured'], true),
                'provider_credential_id',
            );
        }

        try {
            Validator::make(
                [
                    'name' => $form->name,
                    'aws_lambda_region' => $form->aws_lambda_region,
                ],
                [
                    'name' => 'required|string|max:255',
                    'aws_lambda_region' => 'required|string|max:255',
                ]
            )->validate();

            $checks[] = $this->check('aws_lambda_config', 'info', __('Lambda target settings look valid'), __('The AWS region is present and ready for repo-first runtime detection.'), false);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $checks[] = $this->check(
                    'aws_lambda_'.$field,
                    'error',
                    __('Missing required Lambda target details'),
                    (string) ($messages[0] ?? __('This field is required.')),
                    true,
                    $field
                );
            }
        }

        $checks[] = $this->check(
            'aws_lambda_runtime',
            'info',
            __('Lambda targets support PHP and Node deploys'),
            __('Laravel and generic PHP repositories can resolve to the AWS Lambda/Bref path, while JavaScript builds can still use the same repo-first detection flow.'),
            false
        );

        return $checks;
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return list<array{key:string,severity:'error'|'warning'|'info',label:string,detail:string,blocking:bool,field:?string}>
     */
    private function selectionChecks(
        string $value,
        array $options,
        string $field,
        string $label,
        string $missingDetail,
        string $unavailableDetail,
    ): array {
        if ($value === '') {
            return [$this->check($field.'_missing', 'error', $label, $missingDetail, true, $field)];
        }

        if ($options !== [] && ! collect($options)->contains(fn (array $option): bool => (string) ($option['value'] ?? '') === $value)) {
            return [$this->check($field.'_unavailable', 'error', $label, $unavailableDetail, true, $field)];
        }

        return [$this->check($field.'_selected', 'info', $label, __('Selected: :value', ['value' => $value]), false, $field)];
    }

    /**
     * @param  array{
     *     credentials: Collection<int, mixed>,
     *     regions: list<array<string, mixed>>,
     *     sizes: list<array<string, mixed>>,
     *     region_label: string,
     *     size_label: string
     * }  $catalog
     * @return array{
     *     state: 'available'|'unavailable'|'incomplete',
     *     provider: string,
     *     region: ?string,
     *     size: ?string,
     *     price_monthly: ?float,
     *     price_hourly: ?float,
     *     formatted_price: ?string,
     *     source: ?string,
     *     detail: string,
     *     extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>,
     *     notes: list<string>
     * }
     */
    private function buildCostPreview(ServerCreateForm $form, array $catalog): array
    {
        if ($form->type === 'custom') {
            return [
                'state' => 'unavailable',
                'provider' => 'custom',
                'region' => null,
                'size' => null,
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => null,
                'detail' => __('Dply cannot estimate pricing for your own VPS. Billing stays with your provider.'),
                'extras' => [],
                'notes' => [__('Billing remains with your current infrastructure provider.')],
            ];
        }

        if ($form->type === 'digitalocean_kubernetes') {
            return $this->buildDoksCostPreview($form, $catalog);
        }

        if ($form->type === 'aws_kubernetes') {
            return $this->buildEksCostPreview($form);
        }

        $size = collect($catalog['sizes'] ?? [])->first(fn (array $option): bool => (string) ($option['value'] ?? '') === $form->size);

        if (! is_array($size)) {
            if ($form->type === 'digitalocean_functions') {
                return [
                    'state' => 'unavailable',
                    'provider' => $form->type,
                    'region' => null,
                    'size' => null,
                    'price_monthly' => null,
                    'price_hourly' => null,
                    'formatted_price' => null,
                    'source' => null,
                    'detail' => __('DigitalOcean Functions pricing depends on invocations, execution time, and memory. Review pricing in DigitalOcean before launch.'),
                    'extras' => [],
                    'notes' => [__('Functions hosts do not use VM region/size catalogs.')],
                ];
            }

            return [
                'state' => 'incomplete',
                'provider' => $form->type,
                'region' => $form->region !== '' ? $form->region : null,
                'size' => $form->size !== '' ? $form->size : null,
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => null,
                'detail' => __('Choose a region and size to preview estimated provider cost.'),
                'extras' => [],
                'notes' => [],
            ];
        }

        $priceMonthly = is_numeric($size['price_monthly'] ?? null) ? round((float) $size['price_monthly'], 2) : null;
        $priceHourly = is_numeric($size['price_hourly'] ?? null) ? round((float) $size['price_hourly'], 4) : null;

        if ($priceMonthly === null && $priceHourly === null) {
            return [
                'state' => 'unavailable',
                'provider' => $form->type,
                'region' => $form->region !== '' ? $form->region : null,
                'size' => $form->size !== '' ? $form->size : null,
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => $size['pricing_source'] ?? null,
                'detail' => __('Pricing is unavailable for this provider selection right now. Continue only after checking pricing in the provider dashboard if cost matters.'),
                'extras' => $this->buildCostExtras($form),
                'notes' => $this->buildCostNotes($form),
            ];
        }

        $formattedPrice = $priceMonthly !== null
            ? '$'.number_format($priceMonthly, $priceMonthly < 10 ? 2 : 0).'/'.__('mo')
            : '$'.number_format((float) $priceHourly, 4).'/'.__('hr');

        return [
            'state' => 'available',
            'provider' => $form->type,
            'region' => $form->region !== '' ? $form->region : null,
            'size' => $form->size !== '' ? $form->size : null,
            'price_monthly' => $priceMonthly,
            'price_hourly' => $priceHourly,
            'formatted_price' => $formattedPrice,
            'source' => $size['pricing_source'] ?? 'provider_catalog',
            'detail' => __('Estimated from the provider catalog for the selected size. Taxes, backups, bandwidth, and add-ons may change the final bill.'),
            'extras' => $this->buildCostExtras($form),
            'notes' => $this->buildCostNotes($form),
        ];
    }

    /**
     * @return list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>
     */
    private function buildCostExtras(ServerCreateForm $form): array
    {
        $extras = [];

        if ($form->type === 'digitalocean') {
            $extras[] = [
                'label' => __('Backups'),
                'state' => $form->do_backups ? 'enabled' : 'optional',
                'detail' => $form->do_backups
                    ? __('DigitalOcean backups are enabled and may increase the final bill.')
                    : __('Backups are optional and billed separately if you enable them.'),
                'amount' => null,
                'amount_period' => null,
            ];
            $extras[] = [
                'label' => __('Monitoring'),
                'state' => $form->do_monitoring ? 'included' : 'optional',
                'detail' => __('DigitalOcean monitoring is informational and does not add a separate provider charge.'),
                'amount' => 0.0,
                'amount_period' => 'monthly',
            ];
            $extras[] = [
                'label' => __('IPv6'),
                'state' => $form->do_ipv6 ? 'included' : 'optional',
                'detail' => __('IPv6 does not add a separate line item, but network and bandwidth usage may still vary.'),
                'amount' => 0.0,
                'amount_period' => null,
            ];
        }

        return $extras;
    }

    /**
     * @return list<string>
     */
    private function buildCostNotes(ServerCreateForm $form): array
    {
        $notes = [];

        if ($form->type === 'digitalocean') {
            $notes[] = __('Bandwidth overages, snapshots, and attached storage are not included in this estimate.');
        } else {
            $notes[] = __('Provider taxes, storage, data transfer, and optional services may change the final bill.');
        }

        return $notes;
    }

    /**
     * Sums the picked DOKS cluster's node-pool droplets (count × monthly droplet
     * price from the DO sizes catalog) and adds DO's HA control plane fee when
     * the cluster has `ha: true`. LBs / storage / bandwidth are listed in notes
     * because they're usage-based and we can't predict them at create time.
     *
     * When the user hasn't picked a specific cluster yet but the catalog already
     * lists clusters from their account, we collapse the per-cluster estimates
     * into a range so the StepWhere sidebar isn't just a dead "Unavailable".
     *
     * @param  array<string, mixed>  $catalog
     * @return array{state: string, provider: string, region: ?string, size: ?string, price_monthly: ?float, price_hourly: ?float, formatted_price: ?string, source: ?string, detail: string, extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>, notes: list<string>}
     */
    private function buildDoksCostPreview(ServerCreateForm $form, array $catalog): array
    {
        $clusterName = $form->do_kubernetes_cluster_name;
        $clusters = is_array($catalog['kubernetes_clusters'] ?? null) ? $catalog['kubernetes_clusters'] : [];
        $priceBySlug = $this->buildDoksPriceBySlugMap($catalog);

        if ($form->do_kubernetes_source === 'new') {
            return $this->buildDoksNewClusterCostPreview($form, $priceBySlug);
        }

        if ($clusterName === '' && $clusters === []) {
            return $this->buildDoksStarterSamplePreview($form, $priceBySlug, $catalog);
        }

        if ($clusterName === '') {
            return $this->buildDoksAggregateCostPreview($form, $clusters, $priceBySlug);
        }

        $cluster = null;
        foreach ($clusters as $candidate) {
            if (is_array($candidate) && (string) ($candidate['name'] ?? '') === $clusterName) {
                $cluster = $candidate;
                break;
            }
        }

        if ($cluster === null) {
            return [
                'state' => 'unavailable',
                'provider' => $form->type,
                'region' => null,
                'size' => null,
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => null,
                'detail' => __('Selected cluster was not found in your DigitalOcean account. Re-pick a cluster to refresh the estimate.'),
                'extras' => [],
                'notes' => [],
            ];
        }

        $cost = $this->computeDoksClusterCost($cluster, $priceBySlug);
        $extras = $cost['extras'];

        if ($cost['is_ha']) {
            $extras[] = [
                'label' => __('HA control plane'),
                'state' => 'included',
                'detail' => __('DigitalOcean charges $40/mo for the highly-available control plane.'),
                'amount' => 40.0,
                'amount_period' => 'monthly',
            ];
        } else {
            $extras[] = [
                'label' => __('Control plane'),
                'state' => 'included',
                'detail' => __('Free for standard (non-HA) DOKS clusters.'),
                'amount' => 0.0,
                'amount_period' => 'monthly',
            ];
        }

        $total = round($cost['total'] + ($cost['is_ha'] ? 40.0 : 0.0), 2);
        $hasUnknownPrice = $cost['has_unknown'];
        $formatted = '$'.number_format($total, $total < 10 ? 2 : 0).'/'.__('mo');

        return [
            'state' => 'available',
            'provider' => $form->type,
            'region' => (string) ($cluster['region'] ?? '') !== '' ? (string) $cluster['region'] : null,
            'size' => $clusterName,
            'price_monthly' => $total,
            'price_hourly' => null,
            'formatted_price' => $hasUnknownPrice ? $formatted.' '.__('(partial)') : $formatted,
            'source' => 'provider_catalog',
            'detail' => $hasUnknownPrice
                ? __('Estimate sums node-pool droplets at DigitalOcean catalog prices. One or more pool sizes were not in the catalog — total is a lower bound.')
                : __('Estimate sums node-pool droplets at DigitalOcean catalog prices plus the control-plane fee.'),
            'extras' => $extras,
            'notes' => [
                __('Load balancers ($12/mo each), block storage, snapshots, and bandwidth overages are billed separately by usage.'),
            ],
        ];
    }

    /**
     * EKS estimate is intentionally partial: AWS charges a flat $73/mo for the
     * control plane on top of node-group EC2 instances and load balancers, both
     * of which need additional API plumbing (DescribeNodegroup + EC2 pricing).
     * We show the control-plane line + a note so the user isn't staring at
     * "Unavailable" with no signal.
     *
     * @return array{state: string, provider: string, region: ?string, size: ?string, price_monthly: ?float, price_hourly: ?float, formatted_price: ?string, source: ?string, detail: string, extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>, notes: list<string>}
     */
    private function buildEksCostPreview(ServerCreateForm $form): array
    {
        $clusterName = $form->do_kubernetes_cluster_name;

        return [
            'state' => 'available',
            'provider' => $form->type,
            'region' => null,
            'size' => $clusterName !== '' ? $clusterName : null,
            'price_monthly' => 73.0,
            'price_hourly' => null,
            'formatted_price' => '$73/'.__('mo').'+',
            'source' => 'aws_published',
            'detail' => __('Starts at $73/mo for the EKS control plane. Node-group EC2 instances and load balancers are billed separately and not summed here.'),
            'extras' => [
                [
                    'label' => __('EKS control plane'),
                    'state' => 'included',
                    'detail' => __('AWS charges $73/mo per EKS cluster control plane.'),
                    'amount' => 73.0,
                    'amount_period' => 'monthly',
                ],
                [
                    'label' => __('Node groups (EC2)'),
                    'state' => 'unknown',
                    'detail' => __('Charged per EC2 instance in each node group. Review your cluster in the AWS console for the running totals.'),
                    'amount' => null,
                    'amount_period' => 'monthly',
                ],
            ],
            'notes' => [
                __('Application load balancers, NAT gateways, EBS volumes, and data transfer are usage-based and not included.'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, float>
     */
    private function buildDoksPriceBySlugMap(array $catalog): array
    {
        $priceBySlug = [];
        foreach ($catalog['sizes'] ?? [] as $size) {
            if (! is_array($size)) {
                continue;
            }
            $slug = (string) ($size['value'] ?? '');
            $monthly = $size['price_monthly'] ?? null;
            if ($slug !== '' && is_numeric($monthly)) {
                $priceBySlug[$slug] = (float) $monthly;
            }
        }

        return $priceBySlug;
    }

    /**
     * Per-cluster node-pool total. Returns the running sum (nodes only, control
     * plane is added by the caller because the HA flag is a per-cluster choice
     * we want to surface as its own line), the extras list of node-pool lines,
     * a flag for any unpriced pool sizes, and the cluster's HA boolean.
     *
     * @param  array<string, mixed>  $cluster
     * @param  array<string, float>  $priceBySlug
     * @return array{total: float, extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>, has_unknown: bool, is_ha: bool}
     */
    private function computeDoksClusterCost(array $cluster, array $priceBySlug): array
    {
        $extras = [];
        $total = 0.0;
        $hasUnknown = false;

        $nodePools = is_array($cluster['node_pools'] ?? null) ? $cluster['node_pools'] : [];
        foreach ($nodePools as $pool) {
            if (! is_array($pool)) {
                continue;
            }
            $poolName = (string) ($pool['name'] ?? __('node pool'));
            $slug = (string) ($pool['size'] ?? '');
            $count = (int) ($pool['count'] ?? 0);
            $unitPrice = $priceBySlug[$slug] ?? null;

            if ($unitPrice === null) {
                $hasUnknown = true;
                $extras[] = [
                    'label' => $poolName.' — '.$count.' × '.$slug,
                    'state' => 'unknown',
                    'detail' => __('Droplet price for :slug not found in the DigitalOcean catalog.', ['slug' => $slug]),
                    'amount' => null,
                    'amount_period' => 'monthly',
                ];

                continue;
            }

            $poolTotal = $unitPrice * $count;
            $total += $poolTotal;
            $extras[] = [
                'label' => $poolName.' — '.$count.' × '.$slug,
                'state' => 'included',
                'detail' => sprintf('$%s × %d', number_format($unitPrice, $unitPrice < 10 ? 2 : 0), $count),
                'amount' => round($poolTotal, 2),
                'amount_period' => 'monthly',
            ];
        }

        return [
            'total' => $total,
            'extras' => $extras,
            'has_unknown' => $hasUnknown,
            'is_ha' => (bool) ($cluster['ha'] ?? false),
        ];
    }

    /**
     * No cluster picked yet but we have credentials + a cluster list. Collapse
     * each cluster's full estimate (nodes + control plane) into a min/max range
     * so the StepWhere sidebar shows real numbers instead of "Unavailable".
     *
     * @param  list<array<string, mixed>|mixed>  $clusters
     * @param  array<string, float>  $priceBySlug
     * @return array{state: string, provider: string, region: ?string, size: ?string, price_monthly: ?float, price_hourly: ?float, formatted_price: ?string, source: ?string, detail: string, extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>, notes: list<string>}
     */
    private function buildDoksAggregateCostPreview(ServerCreateForm $form, array $clusters, array $priceBySlug): array
    {
        $perCluster = [];
        $hasAnyUnknown = false;
        foreach ($clusters as $cluster) {
            if (! is_array($cluster)) {
                continue;
            }
            $cost = $this->computeDoksClusterCost($cluster, $priceBySlug);
            $total = round($cost['total'] + ($cost['is_ha'] ? 40.0 : 0.0), 2);
            $hasAnyUnknown = $hasAnyUnknown || $cost['has_unknown'];
            $perCluster[] = [
                'name' => (string) ($cluster['name'] ?? ''),
                'region' => (string) ($cluster['region'] ?? ''),
                'total' => $total,
                'is_ha' => $cost['is_ha'],
                'has_unknown' => $cost['has_unknown'],
            ];
        }

        if ($perCluster === []) {
            return [
                'state' => 'unavailable',
                'provider' => $form->type,
                'region' => null,
                'size' => null,
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => null,
                'detail' => __('Pick a cluster on the next step to see an estimated monthly cost based on its node pools.'),
                'extras' => [],
                'notes' => [__('DigitalOcean charges for the droplets behind each node pool, plus load balancers, block storage, and bandwidth usage.')],
            ];
        }

        $totals = array_column($perCluster, 'total');
        $min = min($totals);
        $max = max($totals);
        $formattedMin = '$'.number_format($min, $min < 10 ? 2 : 0);
        $formattedMax = '$'.number_format($max, $max < 10 ? 2 : 0);
        $formatted = $min === $max
            ? $formattedMin.'/'.__('mo')
            : $formattedMin.' – '.$formattedMax.'/'.__('mo');
        if ($hasAnyUnknown) {
            $formatted .= ' '.__('(partial)');
        }

        $extras = [];
        foreach ($perCluster as $entry) {
            $label = $entry['name'] !== '' ? $entry['name'] : __('cluster');
            if ($entry['region'] !== '') {
                $label .= ' — '.$entry['region'];
            }
            if ($entry['is_ha']) {
                $label .= ' '.__('(HA)');
            }
            $extras[] = [
                'label' => $label,
                'state' => $entry['has_unknown'] ? 'partial' : 'candidate',
                'detail' => $entry['has_unknown']
                    ? __('Lower bound — one or more node pool sizes weren\'t in the DigitalOcean catalog.')
                    : __('Sum of node-pool droplets plus the cluster\'s control plane fee.'),
                'amount' => $entry['total'],
                'amount_period' => 'monthly',
            ];
        }

        return [
            'state' => 'available',
            'provider' => $form->type,
            'region' => null,
            'size' => count($perCluster) === 1
                ? $perCluster[0]['name']
                : __(':n clusters available', ['n' => count($perCluster)]),
            'price_monthly' => count($perCluster) === 1 ? $perCluster[0]['total'] : null,
            'price_hourly' => null,
            'formatted_price' => $formatted,
            'source' => 'provider_catalog',
            'detail' => count($perCluster) === 1
                ? __('Estimate for the only DOKS cluster in this account. The next step lets you confirm or pick a different one.')
                : __('Range across the DOKS clusters in this account. Pick a specific cluster on the next step to lock in the estimate.'),
            'extras' => $extras,
            'notes' => [
                __('Load balancers ($12/mo each), block storage, snapshots, and bandwidth overages are billed separately by usage.'),
            ],
        ];
    }

    /**
     * Shown when the user is in 'existing' mode but their DO account has zero
     * DOKS clusters yet. Rather than leaving the sidebar blank, we price a
     * sample 2-node starter cluster on the cheapest "real" droplet size (≥2GB
     * RAM — anything smaller is rarely useful for K8s nodes) and point them at
     * the Create New toggle to actually provision it.
     *
     * @param  array<string, float>  $priceBySlug
     * @param  array<string, mixed>  $catalog
     * @return array{state: string, provider: string, region: ?string, size: ?string, price_monthly: ?float, price_hourly: ?float, formatted_price: ?string, source: ?string, detail: string, extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>, notes: list<string>}
     */
    private function buildDoksStarterSamplePreview(ServerCreateForm $form, array $priceBySlug, array $catalog): array
    {
        $starter = $this->pickDoksStarterSize($catalog);

        if ($starter === null || ! isset($priceBySlug[$starter['value']])) {
            return [
                'state' => 'unavailable',
                'provider' => $form->type,
                'region' => null,
                'size' => null,
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => null,
                'detail' => __('No clusters in this account yet. Switch to "Create new" on the next step so dply can provision one for you.'),
                'extras' => [],
                'notes' => [__('DigitalOcean charges for the droplets behind each node pool, plus load balancers, block storage, and bandwidth usage.')],
            ];
        }

        $unitPrice = $priceBySlug[$starter['value']];
        $nodes = 2;
        $total = round($unitPrice * $nodes, 2);
        $formatted = '$'.number_format($total, $total < 10 ? 2 : 0).'/'.__('mo');

        return [
            'state' => 'available',
            'provider' => $form->type,
            'region' => null,
            'size' => __('Sample starter cluster'),
            'price_monthly' => $total,
            'price_hourly' => null,
            'formatted_price' => $formatted.' '.__('(sample)'),
            'source' => 'provider_catalog',
            'detail' => __('No clusters in this account yet. This is what a starter cluster would cost — switch to "Create new" on the next step to actually provision it.'),
            'extras' => [
                [
                    'label' => __('Default pool').' — '.$nodes.' × '.$starter['value'],
                    'state' => 'sample',
                    'detail' => sprintf('$%s × %d', number_format($unitPrice, $unitPrice < 10 ? 2 : 0), $nodes),
                    'amount' => $total,
                    'amount_period' => 'monthly',
                ],
                [
                    'label' => __('Control plane'),
                    'state' => 'sample',
                    'detail' => __('Free for standard (non-HA) DOKS clusters.'),
                    'amount' => 0.0,
                    'amount_period' => 'monthly',
                ],
            ],
            'notes' => [
                __('This is a sample, not a real cluster — nothing is provisioned until you complete the wizard with "Create new" selected.'),
                __('Load balancers ($12/mo each), block storage, and bandwidth are billed separately by usage.'),
            ],
        ];
    }

    /**
     * Picks the cheapest droplet size with at least 2GB of memory — small enough
     * to be a sane "starter" pool and large enough that K8s system pods fit. Falls
     * back to the cheapest priced size when nothing meets the RAM floor.
     *
     * @param  array<string, mixed>  $catalog
     * @return array{value: string, price_monthly: float}|null
     */
    private function pickDoksStarterSize(array $catalog): ?array
    {
        $candidates = [];
        foreach ($catalog['sizes'] ?? [] as $size) {
            if (! is_array($size)) {
                continue;
            }
            $slug = (string) ($size['value'] ?? '');
            $monthly = $size['price_monthly'] ?? null;
            if ($slug === '' || ! is_numeric($monthly)) {
                continue;
            }
            $memMb = (int) ($size['memory_mb'] ?? 0);
            $candidates[] = [
                'value' => $slug,
                'price_monthly' => (float) $monthly,
                'memory_mb' => $memMb,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $a['price_monthly'] <=> $b['price_monthly']);

        foreach ($candidates as $c) {
            if ($c['memory_mb'] >= 2048) {
                return ['value' => $c['value'], 'price_monthly' => $c['price_monthly']];
            }
        }

        return ['value' => $candidates[0]['value'], 'price_monthly' => $candidates[0]['price_monthly']];
    }

    /**
     * Cost preview for the "Create new cluster" path. We don't have a cluster yet
     * (dply will POST to DO on submit), so the math is straight from the form: the
     * proposed node-pool size × count, plus DO's HA fee when toggled. Same shape
     * as the existing-cluster preview so the sidebar blade renders uniformly.
     *
     * @param  array<string, float>  $priceBySlug
     * @return array{state: string, provider: string, region: ?string, size: ?string, price_monthly: ?float, price_hourly: ?float, formatted_price: ?string, source: ?string, detail: string, extras: list<array{label:string,state:string,detail:string,amount:?float,amount_period:?string}>, notes: list<string>}
     */
    private function buildDoksNewClusterCostPreview(ServerCreateForm $form, array $priceBySlug): array
    {
        $name = $form->do_kubernetes_new_name;
        $region = $form->do_kubernetes_new_region;
        $size = $form->do_kubernetes_new_node_size;
        $count = max(0, $form->do_kubernetes_new_node_count);
        $isHa = $form->do_kubernetes_new_ha;

        if ($size === '' || $count <= 0) {
            return [
                'state' => 'unavailable',
                'provider' => $form->type,
                'region' => $region !== '' ? $region : null,
                'size' => $name !== '' ? $name : __('new cluster'),
                'price_monthly' => null,
                'price_hourly' => null,
                'formatted_price' => null,
                'source' => null,
                'detail' => __('Pick a node droplet size and count to estimate the monthly cost of the cluster dply will create.'),
                'extras' => [],
                'notes' => [__('You are about to provision a brand-new DOKS cluster. Charges begin once DigitalOcean reports the cluster as running.')],
            ];
        }

        $unitPrice = $priceBySlug[$size] ?? null;
        $extras = [];
        if ($unitPrice === null) {
            $extras[] = [
                'label' => __('Default pool').' — '.$count.' × '.$size,
                'state' => 'unknown',
                'detail' => __('Droplet price for :slug not found in the DigitalOcean catalog.', ['slug' => $size]),
                'amount' => null,
                'amount_period' => 'monthly',
            ];
            $nodeTotal = 0.0;
            $hasUnknown = true;
        } else {
            $nodeTotal = $unitPrice * $count;
            $extras[] = [
                'label' => __('Default pool').' — '.$count.' × '.$size,
                'state' => 'included',
                'detail' => sprintf('$%s × %d', number_format($unitPrice, $unitPrice < 10 ? 2 : 0), $count),
                'amount' => round($nodeTotal, 2),
                'amount_period' => 'monthly',
            ];
            $hasUnknown = false;
        }

        if ($isHa) {
            $extras[] = [
                'label' => __('HA control plane'),
                'state' => 'included',
                'detail' => __('DigitalOcean charges $40/mo for the highly-available control plane.'),
                'amount' => 40.0,
                'amount_period' => 'monthly',
            ];
        } else {
            $extras[] = [
                'label' => __('Control plane'),
                'state' => 'included',
                'detail' => __('Free for standard (non-HA) DOKS clusters.'),
                'amount' => 0.0,
                'amount_period' => 'monthly',
            ];
        }

        $total = round($nodeTotal + ($isHa ? 40.0 : 0.0), 2);
        $formatted = '$'.number_format($total, $total < 10 ? 2 : 0).'/'.__('mo');
        if ($hasUnknown) {
            $formatted .= ' '.__('(partial)');
        }

        return [
            'state' => 'available',
            'provider' => $form->type,
            'region' => $region !== '' ? $region : null,
            'size' => $name !== '' ? $name : __('new cluster'),
            'price_monthly' => $total,
            'price_hourly' => null,
            'formatted_price' => $formatted,
            'source' => 'provider_catalog',
            'detail' => $hasUnknown
                ? __('Estimate for the cluster dply will create. One or more sizes weren\'t in the catalog — total is a lower bound.')
                : __('Estimate for the cluster dply will create on submit. You will be charged by DigitalOcean once the cluster is running.'),
            'extras' => $extras,
            'notes' => [
                __('Load balancers ($12/mo each), block storage, snapshots, and bandwidth overages are billed separately by usage.'),
                __('Provisioning starts when you click "Create server" on the next step. Cancelling later requires deleting the cluster in DigitalOcean.'),
            ],
        ];
    }

    /**
     * @param  list<array{key:string,severity:'error'|'warning'|'info',label:string,detail:string,blocking:bool,field:?string}>  $checks
     * @return array<string, list<array{key:string,severity:'error'|'warning'|'info',label:string,detail:string,blocking:bool,field:?string}>>
     */
    private function groupChecks(array $checks, string $type): array
    {
        $groups = [
            'account_readiness' => [],
            'infrastructure_selection' => [],
            'stack_readiness' => [],
            'verification' => [],
            'cost_clarity' => [],
        ];

        foreach ($checks as $check) {
            $group = match (true) {
                in_array($check['key'], ['provider_credentials', 'provider_credential_id', 'provider_health', 'server_limit', 'user_ssh_keys', 'user_ssh_key_defaults'], true) => 'account_readiness',
                str_starts_with($check['key'], 'region_'), str_starts_with($check['key'], 'size_'), in_array($check['key'], ['regions_unverified', 'sizes_unverified'], true) => 'infrastructure_selection',
                str_starts_with($check['key'], 'stack_'), $check['key'] === 'stack' => 'stack_readiness',
                $check['key'] === 'custom_connection' || $check['key'] === 'custom_verification' => 'verification',
                default => $type === 'custom' ? 'verification' : 'cost_clarity',
            };

            $groups[$group][] = $check;
        }

        return array_filter($groups, static fn (array $group): bool => $group !== []);
    }

    /**
     * @return array{key:string,severity:'error'|'warning'|'info',label:string,detail:string,blocking:bool,field:?string}
     */
    private function check(
        string $key,
        string $severity,
        string $label,
        string $detail,
        bool $blocking,
        ?string $field = null,
    ): array {
        return [
            'key' => $key,
            'severity' => $severity,
            'label' => $label,
            'detail' => $detail,
            'blocking' => $blocking,
            'field' => $field,
        ];
    }
}
