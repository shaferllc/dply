<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sites\DeployControlToolbarTest;

use App\Livewire\Sites\DeployControl;
use App\Livewire\Sites\DeploymentsList;
use App\Livewire\Sites\EnvQuickEdit;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The Deploy / Console / Sync / .env toolbar is nested inside site breadcrumbs.
 * Lazy pages (Deployments) remount those children on a Livewire request with
 * no route {site}, so the components must honor an explicit site prop.
 */
test('deploy control renders when the site is passed without a route binding', function (): void {
    [$user, $server, $site] = makeVmSite();

    Livewire::actingAs($user)
        ->test(DeployControl::class, ['site' => $site, 'server' => $server])
        ->assertSet('site.id', $site->id)
        ->assertSee(__('Deploy'))
        ->assertSee(__('Console'));
});

test('env quick edit renders when the site is passed without a route binding', function (): void {
    [$user, $server, $site] = makeVmSite();

    Livewire::actingAs($user)
        ->test(EnvQuickEdit::class, ['site' => $site, 'server' => $server])
        ->assertSet('site.id', $site->id)
        ->assertSee(__('.env'));
});

test('deploy control stays empty when no site can be resolved', function (): void {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(DeployControl::class)
        ->assertSet('site', null);

    expect($component->instance()->canDeploy())->toBeFalse();
    $component->assertDontSeeHtml('wire:click="deploy"');
});

test('deployments page mounts the breadcrumb deploy and env controls', function (): void {
    [$user, $server, $site] = makeVmSite();

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->assertSeeLivewire(DeployControl::class)
        ->assertSeeLivewire(EnvQuickEdit::class);
});

/** @return array{0: User, 1: Server, 2: Site} */
function makeVmSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $server, $site];
}
