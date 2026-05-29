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

    // Two servers → Starter ($9 flat) under the plan model.
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'starter', 'label' => 'Starter', 'price_cents' => 900, 'max_servers' => 3],
        tierQuantities: ['xs' => 2],
    );

    $observatory = new OrganizationCostObservatory(
        new ServerMonthlyCostNoteParser,
        app(ServerProviderCostEstimator::class),
    );

    $result = $observatory->forOrganization($org, $state);

    expect($result['dply_platform_cents'])->toBe(900)
        ->and($result['provider_infrastructure_cents'])->toBe(1800)
        ->and($result['stack_total_cents'])->toBe(2700)
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

    // One server → Free plan ($0).
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'free', 'label' => 'Free', 'price_cents' => 0, 'max_servers' => 1],
        tierQuantities: ['xs' => 1],
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
