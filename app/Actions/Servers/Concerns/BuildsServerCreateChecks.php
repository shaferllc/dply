<?php

declare(strict_types=1);

namespace App\Actions\Servers\Concerns;

use App\Actions\Servers\ServerProvisionPreferenceRules;
use App\Actions\Servers\StoreServerFromCreateForm;
use App\Livewire\Forms\ServerCreateForm;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsServerCreateChecks
{


    /**
     * @param  array{
     *     credentials: \Illuminate\Support\Collection<int, mixed>,
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
     * @param  array<string, mixed>|null  $providerHealth
     * @param  array<string, array{state: string, label: string, detail: string}>  $sizeRecommendations
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
            options: $catalog['regions'],
            field: 'region',
            label: $catalog['region_label'],
            missingDetail: __('Choose a region before creating the server.'),
            unavailableDetail: __('The selected region is not available for the current catalog response.')
        )];

        $checks = [...$checks, ...$this->selectionChecks(
            value: $form->size,
            options: $catalog['sizes'],
            field: 'size',
            label: $catalog['size_label'],
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

        if ($catalog['regions'] === [] && $form->provider_credential_id !== '') {
            $checks[] = $this->check('regions_unverified', 'warning', __('Region availability could not be verified'), __('The provider catalog did not return regions just now. You can continue if you trust the current selection.'), false, 'region');
        }

        if ($catalog['sizes'] === [] && $form->provider_credential_id !== '') {
            $checks[] = $this->check('sizes_unverified', 'warning', __('Size availability could not be verified'), __('The provider catalog did not return plan or size data just now. You can continue if you trust the current selection.'), false, 'size');
        }

        $sizeValue = $form->size;
        if ($sizeValue !== '' && isset($sizeRecommendations[$sizeValue])) {
            $recommendation = $sizeRecommendations[$sizeValue];
            /** @var list<array{id: string, label?: string}> $serverRoles */
            $serverRoles = config('server_provision_options.server_roles', []);
            $role = collect($serverRoles)
                ->firstWhere('id', $form->server_role);
            $roleLabel = is_array($role) && filled($role['label'] ?? null)
                ? (string) $role['label']
                : str($form->server_role)->replace('_', ' ')->title()->toString();
            // A too-small box for a memory-heavy role risks OOM during the
            // install itself (cloud-init/apt getting killed on sub-1GB droplets),
            // which can leave a half-built server. Surface that clearly for
            // application/database roles — but as a WARNING, never a block: the
            // operator decides.
            $tooSmall = $recommendation['state'] === 'too_small';
            $memoryHeavyRole = in_array($form->server_role, ['application', 'database'], true);
            $oomRisk = $tooSmall && $memoryHeavyRole;

            $checks[] = $this->check(
                'size_recommendation',
                $tooSmall ? 'warning' : 'info',
                __('Sizing for :role', ['role' => $roleLabel]),
                $oomRisk
                    ? $recommendation['detail'].' '.__('This plan is small enough that setup may run out of memory mid-install — 2 GB+ is recommended for this role.')
                    : $recommendation['detail'],
                false,
                'size',
            );
        }

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $customConnectionTest
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
        /** @var list<array{id: string}> $installProfiles */
        $installProfiles = config('server_provision_options.install_profiles', []);
        $installProfileIds = array_values(array_filter(array_column($installProfiles, 'id'), 'is_string'));

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
            $connectionState = (string) $customConnectionTest['state'];
            $checks[] = $this->check(
                'custom_verification',
                $connectionState === 'error' ? 'error' : 'warning',
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
     * @param  array{
     *     credentials: \Illuminate\Support\Collection<int, mixed>,
     *     regions: list<array<string, mixed>>,
     *     sizes: list<array<string, mixed>>,
     *     region_label: string,
     *     size_label: string
     * }  $catalog
     * @param  array<string, mixed>|null  $providerHealth
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
     * @param  array{
     *     credentials: \Illuminate\Support\Collection<int, mixed>,
     *     regions: list<array<string, mixed>>,
     *     sizes: list<array<string, mixed>>,
     *     region_label: string,
     *     size_label: string
     * }  $catalog
     * @param  array<string, mixed>|null  $providerHealth
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
     * @param  array{
     *     credentials: \Illuminate\Support\Collection<int, mixed>,
     *     regions: list<array<string, mixed>>,
     *     sizes: list<array<string, mixed>>,
     *     region_label: string,
     *     size_label: string
     * }  $catalog
     * @param  array<string, mixed>|null  $providerHealth
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
