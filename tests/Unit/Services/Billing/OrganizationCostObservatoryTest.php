<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Server;
use App\Services\Billing\DesiredBillingState;
use App\Services\Billing\OrganizationCostObservatory;
use App\Services\Billing\ServerMonthlyCostNoteParser;
use App\Services\Servers\ServerProviderCostEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('cost observatory sums dply fees and parsed provider notes', function () {
    $org = Organization::factory()->create();

    Server::factory()->for($org)->create([
        'status' => Server::STATUS_READY,
        'created_at' => now()->subDays(5),
        'meta' => ['cost_monthly_note' => '~$6.00/mo · Hetzner cx11'],
    ]);

    Server::factory()->for($org)->create([
        'status' => Server::STATUS_READY,
        'created_at' => now()->subDays(5),
        'meta' => ['cost_monthly_note' => '$12/mo DO s-1vcpu-1gb'],
    ]);

    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['xs' => 2, 's' => 0, 'm' => 0, 'l' => 0, 'xl' => 0],
        baseCents: 1500,
        creditCents: 0,
        tierPricesCents: ['xs' => 200, 's' => 500, 'm' => 1000, 'l' => 2000, 'xl' => 4000],
    );

    $observatory = new OrganizationCostObservatory(
        new ServerMonthlyCostNoteParser,
        app(ServerProviderCostEstimator::class),
    );

    $result = $observatory->forOrganization($org, $state);

    expect($result['dply_platform_cents'])->toBe(1900)
        ->and($result['provider_infrastructure_cents'])->toBe(1800)
        ->and($result['stack_total_cents'])->toBe(3700)
        ->and($result['provider_partial'])->toBeFalse()
        ->and($result['comparison']['forge_baseline_cents'])->toBe(2400);
});

test('cost observatory marks servers without notes as unknown', function () {
    $org = Organization::factory()->create();

    Server::factory()->for($org)->create([
        'status' => Server::STATUS_READY,
        'created_at' => now()->subDays(5),
        'provider' => 'custom',
    ]);

    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['xs' => 1, 's' => 0, 'm' => 0, 'l' => 0, 'xl' => 0],
        baseCents: 1500,
        creditCents: 0,
        tierPricesCents: ['xs' => 200, 's' => 500, 'm' => 1000, 'l' => 2000, 'xl' => 4000],
    );

    $observatory = new OrganizationCostObservatory(
        new ServerMonthlyCostNoteParser,
        app(ServerProviderCostEstimator::class),
    );

    $result = $observatory->forOrganization($org, $state);

    expect($result['provider_infrastructure_cents'])->toBe(0)
        ->and($result['provider_partial'])->toBeTrue()
        ->and($result['provider_unknown_count'])->toBe(1)
        ->and($result['servers'][0]['source'])->toBe('unknown');
});
