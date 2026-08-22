<?php

namespace Tests\Feature\Livewire\Sites\SectionChromeTest;

use App\Livewire\Sites\Settings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function chromeFixture(array $siteAttributes = []): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $organization->id]);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);
    $site = Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ], $siteAttributes));

    return [$user, $server, $site];
}

/**
 * The floating hero header is the pre-merged-chrome layout. Sections that have
 * been converted render one outer card with a sand identity header instead, so
 * seeing a hero on a converted section means it fell out of the list.
 */
it('renders Platform on merged chrome, like Notifications', function () {
    [$user, $server, $site] = chromeFixture();

    Livewire::withoutLazyLoading();

    $platform = Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'platform'])
        ->html();

    $notifications = Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'notifications'])
        ->html();

    expect($platform)->not->toContain('data-hero-card')
        ->and($notifications)->not->toContain('data-hero-card');
});

it('renders every converted section without a floating hero', function () {
    [$user, $server, $site] = chromeFixture([
        'status' => Site::STATUS_NGINX_ACTIVE,
        'meta' => [],
    ]);

    Livewire::withoutLazyLoading();

    foreach (['backends', 'deploy', 'wordpress', 'rails-stack'] as $section) {
        $html = Livewire::actingAs($user)
            ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => $section])
            ->html();

        expect($html)->not->toContain('data-hero-card', "section [{$section}] still renders a hero");
    }
});

it('keeps the hero marker working, so the assertions above cannot pass vacuously', function () {
    // Every section this router renders is converted now, so the control is the
    // component itself rather than another section.
    $html = Blade::render('<x-hero-card title="Control" description="d" icon="server" />');

    expect($html)->toContain('data-hero-card');
});

it('sends the environment URL to the component that can render it', function () {
    [$user, $server, $site] = chromeFixture([
        'status' => Site::STATUS_NGINX_ACTIVE,
        'meta' => [],
    ]);

    Livewire::actingAs($user)
        ->test(Settings::class, ['server' => $server, 'site' => $site, 'section' => 'environment'])
        ->assertRedirect(route('sites.environment', ['server' => $server, 'site' => $site]));
});
