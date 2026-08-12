<?php

namespace Tests\Feature\ServerSettingsDangerTabTest;

use App\Enums\ServerProvider;
use App\Livewire\Servers\SettingsCard;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ownerWithServer(array $serverAttributes = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create(array_merge([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
    ], $serverAttributes));

    return [$user, $server];
}

function dangerTab(User $user, Server $server)
{
    return Livewire::actingAs($user)
        ->test(SettingsCard::class, ['server' => $server])
        ->set('section', 'danger');
}

test('the danger tab explains the blast radius before anything is confirmed', function () {
    [$user, $server] = ownerWithServer();
    Site::factory()->create(['server_id' => $server->id, 'name' => 'shop.example.com']);

    dangerTab($user, $server)
        ->assertOk()
        ->assertSee('What removal does')
        ->assertSee('In dply')
        ->assertSee('What this server currently holds')
        ->assertSee('Sites that go with it:')
        ->assertSee('shop.example.com')
        ->assertSee('How it happens')
        ->assertSee('In 30 minutes')
        ->assertSee('Remove or schedule removal…', escape: false);
});

test('a provider-backed server warns that the instance is destroyed', function () {
    [$user, $server] = ownerWithServer([
        'provider' => ServerProvider::DigitalOcean,
        'provider_id' => '12345678',
    ]);

    dangerTab($user, $server)
        ->assertSee('The instance is destroyed at the provider')
        ->assertSee('12345678');
});

test('a custom server says the machine keeps running and billing', function () {
    [$user, $server] = ownerWithServer([
        'provider' => ServerProvider::Custom,
        'provider_id' => null,
    ]);

    dangerTab($user, $server)
        ->assertSee('Nothing is destroyed at the provider', escape: false)
        ->assertDontSee('The instance is destroyed at the provider');
});

test('a scheduled removal is surfaced with a one-click cancel', function () {
    [$user, $server] = ownerWithServer();

    $server->update([
        'scheduled_deletion_at' => now()->addDays(3),
        'meta' => array_merge($server->meta ?? [], ['scheduled_deletion_reason' => 'Decommissioning the staging box']),
    ]);

    $card = dangerTab($user, $server->fresh())
        ->assertSee('This server is scheduled for removal')
        ->assertSee('Decommissioning the staging box')
        ->assertSee('Cancel scheduled removal');

    $card->call('cancelScheduledServerRemoval');

    expect($server->fresh()->scheduled_deletion_at)->toBeNull();
});
