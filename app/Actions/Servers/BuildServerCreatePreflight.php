<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Livewire\Forms\ServerCreateForm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
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
    ): array {
        $checks = match ($form->type) {
            'custom' => $this->customChecks($form, $canCreateServer, $hasUserSshKeys, $hasProvisionableUserSshKeys, $customConnectionTest),
            'digitalocean_functions' => $this->digitalOceanFunctionsChecks($form, $catalog, $canCreateServer, $hasAnyProviderCredentials, $hasLinkedCredential, $providerHealth),
            'digitalocean_kubernetes' => $this->digitalOceanKubernetesChecks($form, $catalog, $canCreateServer, $hasAnyProviderCredentials, $hasLinkedCredential, $providerHealth),
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
            $checks[] = $this->check(
                'size_recommendation',
                $recommendation['state'] === 'too_small' ? 'warning' : 'info',
                __('Sizing guidance'),
                $recommendation['detail'],
                false,
                'size',
            );
        }

        return $checks;
    }

    /**
     * @return list<array{key:string,severity:'error'|'warning'|'info',label:string,detail:string,blocking:bool,field:?string}>
     */
    private function customChecks(ServerCreateForm $form, bool $canCreateServer, bool $hasUserSshKeys, bool $hasProvisionableUserSshKeys, array $customConnectionTest): array
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
    ): array {
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

        try {
            Validator::make(
                [
                    'name' => $form->name,
                    'do_kubernetes_cluster_name' => $form->do_kubernetes_cluster_name,
                    'do_kubernetes_namespace' => $form->do_kubernetes_namespace,
                ],
                [
                    'name' => 'required|string|max:255',
                    'do_kubernetes_cluster_name' => 'required|string|max:255',
                    'do_kubernetes_namespace' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
                ]
            )->validate();

            $checks[] = $this->check('kubernetes_config', 'info', __('Kubernetes target settings look valid'), __('The cluster name and namespace are present.'), false);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $checks[] = $this->check(
                    'kubernetes_'.$field,
                    'error',
                    __('Missing required Kubernetes target details'),
                    (string) ($messages[0] ?? __('This field is required.')),
                    true,
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

        $size = collect($catalog['sizes'] ?? [])->first(fn (array $option): bool => (string) ($option['value'] ?? '') === $form->size);

        if (! is_array($size)) {
            if (in_array($form->type, ['digitalocean_functions', 'digitalocean_kubernetes'], true)) {
                return [
                    'state' => 'unavailable',
                    'provider' => $form->type,
                    'region' => null,
                    'size' => null,
                    'price_monthly' => null,
                    'price_hourly' => null,
                    'formatted_price' => null,
                    'source' => null,
                    'detail' => $form->type === 'digitalocean_kubernetes'
                        ? __('DigitalOcean Kubernetes pricing depends on node pools, load balancers, storage, and network usage. Review pricing in DigitalOcean before launch.')
                        : __('DigitalOcean Functions pricing depends on invocations, execution time, and memory. Review pricing in DigitalOcean before launch.'),
                    'extras' => [],
                    'notes' => [$form->type === 'digitalocean_kubernetes'
                        ? __('Managed Kubernetes targets do not use VM region/size catalogs in this create flow.')
                        : __('Functions hosts do not use VM region/size catalogs.')],
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
