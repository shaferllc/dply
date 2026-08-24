<?php

namespace Tests\Feature\DashboardTest;

use App\Livewire\Dashboard;
use App\Models\InsightFinding;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('dashboard is displayed for authenticated user', function () {
    $user = userWithOrganization();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Welcome back');
    $response->assertSee('No servers yet');
    $response->assertSee('Add a server');
    $response->assertSee(route('servers.create'), false);
});

test('dashboard prompts for provider setup when no provider credentials exist', function () {
    $user = userWithOrganization();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Add provider credentials before you provision');
    $response->assertSee('Connect a supported infrastructure provider so this workspace can launch and manage real servers instead of stopping at setup.');
});

test('dashboard redirects guest to login', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login', absolute: false));
});

test('fleet table sorts the worst server first and the attention filter keeps it', function () {
    $user = userWithOrganization();

    $quiet = Server::factory()->for($user)->create(['name' => 'quiet-box']);
    $noisy = Server::factory()->for($user)->create(['name' => 'noisy-box']);

    foreach (['disk', 'memory'] as $key) {
        InsightFinding::query()->create([
            'server_id' => $noisy->id,
            'site_id' => null,
            'team_id' => null,
            'insight_key' => $key,
            'dedupe_hash' => $key,
            'status' => InsightFinding::STATUS_OPEN,
            'severity' => InsightFinding::SEVERITY_CRITICAL,
            'title' => 'Trouble on '.$key,
            'body' => 'Needs attention',
            'meta' => [],
            'correlation' => null,
            'detected_at' => now(),
            'resolved_at' => null,
        ]);
    }

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSeeInOrder(['noisy-box', 'quiet-box'])
        ->assertSee('2 critical')
        ->set('filter', 'attention')
        ->assertSee('noisy-box')
        ->assertDontSee('quiet-box')
        ->set('filter', 'all')
        ->set('q', 'quiet')
        ->assertSee('quiet-box')
        ->assertDontSee('noisy-box');
});
