<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The standalone Containers launcher (/launches/containers/create) was retired
 * in 2026-05 as part of the container flow inversion. The route is kept for one
 * release as a 302 to /servers/create?host_target=docker so external bookmarks
 * (and the old launcher tile, before users refresh) don't 404.
 *
 * Once the redirect is removed in a follow-up PR, delete this file.
 */
final class LaunchesContainersCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_launcher_route_redirects_to_server_create_wizard(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $organization->id]);

        $this->actingAs($user)
            ->get(route('launches.containers.create'))
            ->assertRedirect('/servers/create?host_target=docker');
    }
}
