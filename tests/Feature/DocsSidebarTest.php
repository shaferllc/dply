<?php

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Livewire\Docs\Sidebar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Docs\ContextualDocResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('docs sidebar opens contextual edge previews guide with breadcrumbs', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'edge-app',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'live_url' => 'https://edge-app.dply.host',
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-previews']))
        ->assertOk();

    expect(app(ContextualDocResolver::class)->resolve())->toBe('edge-previews');

    Livewire::actingAs($user)
        ->test(Sidebar::class)
        ->call('open', slug: 'edge-previews')
        ->assertSet('slug', 'edge-previews')
        ->assertSet('visible', true)
        ->assertSee('Edge guides', false)
        ->assertSee('Edge previews', false)
        ->assertSee('Where previews appear', false);
});

test('edge previews doc renders markdown tables as stacked spec cards', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Sidebar::class)
        ->call('open', 'edge-previews')
        ->assertSee('Production site', false)
        ->assertSee('Preview child', false)
        ->assertSee('docs-spec-list', false)
        ->assertSee('docs-spec-card', false)
        ->assertDontSee('docs-table-scroll', false);
});

test('docs sidebar loads edge build guide', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Sidebar::class)
        ->call('open', 'edge-build')
        ->assertSet('slug', 'edge-build')
        ->assertSet('visible', true)
        ->assertSee('Edge build settings')
        ->assertSee('Build command');
});

test('all edge markdown slugs render in docs sidebar', function () {
    $user = User::factory()->create();
    $slugs = config('docs.groups.edge.slugs', []);

    expect($slugs)->not->toBeEmpty();

    foreach ($slugs as $slug) {
        Livewire::actingAs($user)
            ->test(Sidebar::class)
            ->call('open', $slug)
            ->assertSet('visible', true)
            ->assertSee(config("docs.markdown.{$slug}.title"));
    }
});

test('edge overview doc renders on full docs route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.markdown', ['slug' => 'edge-overview']))
        ->assertOk()
        ->assertSeeText('Edge overview')
        ->assertSee('What Edge is for', false);
});

test('app layout includes docs sidebar livewire component', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeLivewire(Sidebar::class);
});

test('docs index lists edge guides', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.index'))
        ->assertOk()
        ->assertSee('Edge overview')
        ->assertSee('Create an Edge app');
});

test('virtual only slug shows summary in sidebar', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Sidebar::class)
        ->call('open', 'create-first-server')
        ->assertSee('Create your first server')
        ->assertSee('Read full guide');
});
