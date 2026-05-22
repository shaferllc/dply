<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchesContainersCreateTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('launcher route redirects to server create wizard', function () {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $organization->id]);

    $this->actingAs($user)
        ->get(route('launches.containers.create'))
        ->assertRedirect('/servers/create?host_target=docker');
});
