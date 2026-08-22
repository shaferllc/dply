<?php

namespace Tests\Feature\Livewire\Serverless\PlatformPanelCliTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Livewire\PlatformPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The panel reads live from the functions host; nothing here needs a real one.
    Http::fake(['*' => Http::response([], 200)]);
});

/**
 * @return array{0: User, 1: Site}
 */
function platformFixture(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $organization->id]);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ]);

    return [$user, $site];
}

it('carries the shared CLI footer on the inspector', function () {
    [$user, $site] = platformFixture();

    Livewire::actingAs($user)
        ->test(PlatformPanel::class, ['site' => $site])
        ->assertSee("dply serverless platform {$site->slug}")
        ->assertSee("dply serverless platform {$site->slug} --schedules")
        ->assertSee("dply serverless invoke {$site->slug}")
        ->assertSee("dply sites:errors {$site->slug}");
});

it('swaps the commands for the tab you are on', function () {
    [$user, $site] = platformFixture();

    Livewire::actingAs($user)
        ->test(PlatformPanel::class, ['site' => $site])
        ->call('setTab', 'console')
        ->assertSee("dply serverless invoke {$site->slug} --method POST --path /health")
        ->assertSee("dply serverless invocations {$site->slug} --source test")
        // The inspector-only rows are gone.
        ->assertDontSee("dply sites:errors {$site->slug}");
});

it('offers dply commands for the namespace key, not a provider CLI', function () {
    [$user, $site] = platformFixture();

    Livewire::actingAs($user)
        ->test(PlatformPanel::class, ['site' => $site])
        ->call('setTab', 'credentials')
        ->assertSee("dply serverless credentials {$site->slug}")
        ->assertSee("dply serverless credentials {$site->slug} --set &lt;key-id&gt;:&lt;secret&gt;", false)
        // The tab used to hand out `doctl` — provider internals stay out of
        // customer copy, and one CLI disclosure per card, not two.
        ->assertDontSee('doctl');
});
