<?php


namespace Tests\Feature\SettingsAndNotificationsTest;
use App\Livewire\Organizations\Automation as OrganizationsAutomation;
use App\Livewire\Settings\Hub as SettingsHub;
use App\Models\NotificationWebhookDestination;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('settings hub is reachable for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.profile'))
        ->assertOk()
        ->assertSee('Profile stores personal preferences on this page', false);
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
        ->test(OrganizationsAutomation::class, ['organization' => $org])
        ->set('deploy_email_notifications_enabled', false);

    $this->assertDatabaseHas('organizations', [
        'id' => $org->id,
        'deploy_email_notifications_enabled' => false,
    ]);
});

test('org admin can add webhook destination from org overview', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    Livewire::actingAs($user)
        ->test(OrganizationsAutomation::class, ['organization' => $org])
        ->set('int_hook_name', 'Ops room')
        ->set('int_hook_driver', NotificationWebhookDestination::DRIVER_SLACK)
        ->set('int_hook_url', 'https://hooks.slack.com/services/T000/B000/XXXX')
        ->set('int_evt_success', true)
        ->set('int_evt_failed', true)
        ->set('int_evt_skipped', false)
        ->call('saveWebhookDestination')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('notification_webhook_destinations', [
        'organization_id' => $org->id,
        'name' => 'Ops room',
        'driver' => NotificationWebhookDestination::DRIVER_SLACK,
        'enabled' => true,
    ]);
});