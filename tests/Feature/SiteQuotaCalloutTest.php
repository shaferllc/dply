<?php

declare(strict_types=1);

namespace Tests\Feature\SiteQuotaCalloutTest;

use App\Livewire\Servers\WorkspaceSites;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The quota block used to render as a thin tinted line under the panel head —
 * chrome, effectively invisible — directly above an empty state that said
 * "Add a site to…" next to a dead Add button. These lock in the loud callout
 * and the fact that it says WHERE the usage is.
 */
function quotaCalloutFixture(): array
{
    config(['subscription.standard.plans' => [
        'free' => ['label' => 'Free', 'price_cents' => 0, 'max_servers' => 1, 'max_sites' => 1, 'max_cloud_apps' => 1, 'max_edge_apps' => 3],
        'business' => ['label' => 'Business', 'price_cents' => 3900, 'max_servers' => null, 'max_sites' => null, 'max_cloud_apps' => null, 'max_edge_apps' => null],
    ]]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $make = fn (array $meta = []) => Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'meta' => $meta,
    ]);

    return [$user, $org, $make];
}

test('a quota-blocked sites panel shouts, names the plan, and says where the usage is', function (): void {
    [$user, $org, $make] = quotaCalloutFixture();

    $emptyVm = $make();
    $otherVm = $make();
    Site::factory()->create(['organization_id' => $org->id, 'server_id' => $otherVm->id]);

    Livewire::actingAs($user)
        ->test(WorkspaceSites::class, ['server' => $emptyVm])
        ->assertSee('Free plan site limit reached — 1 of 1 used')
        ->assertSee('The limit is org-wide, not per server — 1 of them is on another server.')
        ->assertSee('Upgrade plan')
        ->assertSee('Review all sites')
        // The empty state must not invite an action the page has just blocked.
        ->assertDontSee('Add a site to manage web server config');
});

test('edge apps do not block the sites panel on an empty vm', function (): void {
    [$user, $org, $make] = quotaCalloutFixture();

    $emptyVm = $make();
    $edgeHost = $make(['host_kind' => Server::HOST_KIND_DPLY_EDGE]);
    Site::factory()->count(3)->create(['organization_id' => $org->id, 'server_id' => $edgeHost->id]);

    Livewire::actingAs($user)
        ->test(WorkspaceSites::class, ['server' => $emptyVm])
        ->assertDontSee('site limit reached')
        ->assertSee('Add a site to manage web server config');
});
