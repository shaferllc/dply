<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeCreatePageTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('surface.edge');

test('guest is redirected from edge create', function () {
    $this->get(route('edge.create'))
        ->assertRedirect(route('login'));
});

test('authenticated user can load edge create form', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.create'))
        ->assertOk()
        ->assertSee('Deploy an edge app')
        ->assertSee('Git repository')
        ->assertSee('Build command override')
        ->assertSee('SPA fallback')
        ->assertSee('Deploy on push');
});

test('returns 404 when surface edge inactive', function () {
    \Laravel\Pennant\Feature::define('surface.edge', fn () => false);
    \Laravel\Pennant\Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.create'))
        ->assertStatus(400);
});

test('rejects ssr-looking detection on deploy', function () {
    $user = ownerWithOrg();

    Livewire::actingAs($user)
        ->test(\App\Livewire\Edge\Create::class)
        ->set('name', 'SSR App')
        ->set('repo', 'acme/next-app')
        ->set('branch', 'main')
        ->set('detectedPlan', [
            'framework' => 'next',
            'start_command' => 'next start',
            'build_command' => 'npm run build',
        ])
        ->call('deploy')
        ->assertNoRedirect();

    expect(\App\Models\Site::query()->count())->toBe(0);
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
