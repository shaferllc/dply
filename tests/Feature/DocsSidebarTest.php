<?php

namespace Tests\Feature;

use App\Livewire\Docs\Sidebar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
