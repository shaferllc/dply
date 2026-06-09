<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Servers\CreateManagedTest;

use App\Jobs\ProvisionHetznerServerJob;
use App\Livewire\Servers\CreateManaged;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function managedServerUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

beforeEach(function () {
    Feature::define('surface.managed_servers', fn () => true);
    config(['managed_servers.hetzner.api_token' => 'dply-platform-token']);
});

test('the page 404s when the managed option is not available', function () {
    config(['managed_servers.hetzner.api_token' => null]);

    Livewire::actingAs(managedServerUser())
        ->test(CreateManaged::class)
        ->assertStatus(404);
});

test('renders the curated catalog and an all-in price', function () {
    config([
        'subscription.standard.managed_server_markup_percent' => 60,
        'subscription.standard.managed_server_cents' => ['cx22' => 450],
    ]);

    Livewire::actingAs(managedServerUser())
        ->test(CreateManaged::class)
        ->assertStatus(200)
        ->assertSee('CX22')
        ->assertSee('7.20'); // $4.50 × 1.6
});

test('creating a managed server dispatches the provision job and redirects', function () {
    Queue::fake();

    Livewire::actingAs(managedServerUser())
        ->test(CreateManaged::class)
        ->set('name', 'managed-web')
        ->set('region', 'fsn1')
        ->set('size', 'cx22')
        ->set('install_profile', 'laravel_app')
        ->call('create')
        ->assertRedirect();

    $server = Server::query()->where('name', 'managed-web')->firstOrFail();
    expect($server->usesManagedHosting())->toBeTrue();

    Queue::assertPushed(ProvisionHetznerServerJob::class);
});
