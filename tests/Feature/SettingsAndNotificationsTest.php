<?php

namespace Tests\Feature\SettingsAndNotificationsTest;

use App\Livewire\Organizations\Settings as OrganizationsSettings;
use App\Livewire\Settings\Hub as SettingsHub;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('settings hub is reachable for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.profile'))
        ->assertOk()
        ->assertSee('Identity, preferences, sessions, and account on this page', false);
});

test('settings hub livewire renders', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SettingsHub::class)
        ->assertOk();
});

test('docs source control renders markdown', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.markdown', ['slug' => 'source-control']))
        ->assertOk()
        ->assertSeeText('Source control & deploy flow');
});

test('docs org roles and limits renders markdown', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.markdown', ['slug' => 'org-roles-and-limits']))
        ->assertOk()
        ->assertSeeText('Organization roles & plan limits');
});

test('docs api renders http api markdown', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.api'))
        ->assertOk()
        ->assertSeeText('HTTP API');
});

test('docs sites and deploy renders markdown', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.markdown', ['slug' => 'sites-and-deploy']))
        ->assertOk()
        ->assertSeeText('Sites, DNS & deploy');
});

test('docs credentials renders markdown', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.markdown', ['slug' => 'credentials']))
        ->assertOk()
        ->assertSeeText('Server providers vs Git');
});

test('docs billing and plans renders markdown', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.markdown', ['slug' => 'billing-and-plans']))
        ->assertOk()
        ->assertSeeText('Billing & plans');
});

test('docs server workspace renders markdown', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.markdown', ['slug' => 'server-workspace']))
        ->assertOk()
        ->assertSeeText('Server workspace overview');
});

test('docs local development renders markdown', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('docs.markdown', ['slug' => 'local-development']))
        ->assertOk()
        ->assertSeeText('Local development');
});

test('org admin can disable deploy email notifications', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    Livewire::actingAs($user)
        ->test(OrganizationsSettings::class, ['organization' => $org])
        ->set('deploy_email_notifications_enabled', false);

    $this->assertDatabaseHas('organizations', [
        'id' => $org->id,
        'deploy_email_notifications_enabled' => false,
    ]);
});
