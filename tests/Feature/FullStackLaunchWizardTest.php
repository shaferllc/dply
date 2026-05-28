<?php

declare(strict_types=1);

namespace Tests\Feature\FullStackLaunchWizardTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

usesFeatures(
    'launch.full_stack_wizard',
    'surface.cloud',
    'surface.edge',
);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('full stack wizard is hidden without feature flag', function (): void {
    Feature::define('launch.full_stack_wizard', fn (): bool => false);
    Feature::flushCache();

    $user = userWithOrganization();

    $this->actingAs($user)
        ->get(route('launches.full-stack'))
        ->assertStatus(400);
});

test('full stack wizard renders repository step', function (): void {
    $user = userWithOrganization();

    $this->actingAs($user)
        ->get(route('launches.full-stack'))
        ->assertOk()
        ->assertSee('Full-stack from one repo')
        ->assertSee('Analyze repository');
});

test('launchpad shows full stack tile when wizard is active', function (): void {
    $user = userWithOrganization();

    $this->actingAs($user)
        ->get(route('launches.create'))
        ->assertOk()
        ->assertSee('Full-stack from one repo')
        ->assertSee(route('launches.full-stack'), false);
});
