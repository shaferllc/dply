<?php

declare(strict_types=1);

namespace Tests\Feature\Console\SnapshotOrganizationBillingCommandTest;

use App\Models\Organization;
use App\Models\OrganizationBillingSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('snapshots all organizations', function () {
    Organization::factory()->count(2)->create();

    $this->artisan('dply:billing:snapshot-organizations')
        ->expectsOutputToContain('Persisted billing snapshots')
        ->assertOk();

    expect(OrganizationBillingSnapshot::query()->count())->toBe(2);
});

test('dry run does not persist snapshots', function () {
    Organization::factory()->create();

    $this->artisan('dply:billing:snapshot-organizations', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run complete')
        ->assertOk();

    expect(OrganizationBillingSnapshot::query()->count())->toBe(0);
});
