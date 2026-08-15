<?php

declare(strict_types=1);

namespace Tests\Feature\InfrastructureOverviewPageTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The hub 404s unless a non-VM surface is live (multi_surface_active()).
    Feature::define('surface.cloud', fn () => true);
    Feature::flushCache();
});

afterEach(function (): void {
    foreach (config('features', []) as $namespace => $flags) {
        foreach ($flags as $leaf => $default) {
            Feature::define("$namespace.$leaf", fn () => (bool) $default);
        }
    }
    Feature::flushCache();
});

test('infrastructure index lists the cross-product operations views', function () {
    [$user] = makeUserOrg();

    $response = $this->actingAs($user)->get(route('infrastructure.index'));

    $response->assertOk()
        ->assertSee('Infrastructure')
        ->assertSee('Compute')
        ->assertSee('Operations')
        // Tile directory links into each operations surface.
        ->assertSee(route('infrastructure.health'), false)
        ->assertSee(route('infrastructure.blast-radius'), false)
        ->assertSee(route('infrastructure.previews'), false)
        // The dropped "Fleet" concept must not resurface in the UI.
        ->assertDontSee('Fleet');
});

test('infrastructure index surfaces headline deploy stats scoped to the org', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
    ]);

    $response = $this->actingAs($user)->get(route('infrastructure.index'));

    $response->assertOk()
        ->assertSee('Servers')
        ->assertSee('In-flight deploys')
        ->assertSee('7-day deploy success');
});

test('infrastructure index counts in-flight deploys', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
    ]);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now()->subMinutes(2),
    ]);

    $response = $this->actingAs($user)->get(route('infrastructure.index'));

    $response->assertOk()->assertSee('In-flight deploys');
});

test('legacy /fleet URLs redirect to their infrastructure equivalents', function () {
    [$user] = makeUserOrg();

    $this->actingAs($user)->get('/fleet')->assertRedirect('/infrastructure');
    $this->actingAs($user)->get('/fleet/health')->assertRedirect('/infrastructure/health');
    $this->actingAs($user)->get('/fleet/blast-radius')->assertRedirect('/infrastructure/blast-radius');
});

/**
 * @return array{0: User, 1: Organization}
 */
function makeUserOrg(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}
