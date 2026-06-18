<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Actions\Servers\Concerns\BuildsDoksCostPreview;
use App\Actions\Servers\Concerns\BuildsServerCreateChecks;
use App\Actions\Servers\Concerns\BuildsServerCreateCostPreview;
use App\Livewire\Forms\ServerCreateForm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BuildServerCreatePreflight
{
    use AsObject;
    use BuildsDoksCostPreview;
    use BuildsServerCreateChecks;
    use BuildsServerCreateCostPreview;

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
     * @param  array<string, mixed>|null  $providerHealth
     * @param  array<string, mixed>  $customConnectionTest
     * @param  array<string, array{state: string, label: string, detail: string}>  $sizeRecommendations
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


}
