<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\InstallDatabaseEngineFromBindingModalTest;

use App\Jobs\InstallDatabaseEngineJob;
use App\Livewire\Sites\ResourceMap;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function bindingModalSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
        'ssh_private_key' => 'fake-key',
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $server, $site];
}

test('installing postgres from the binding modal queues the engine job and stays on the page', function () {
    Queue::fake();
    [$user, $server, $site] = bindingModalSite();

    $component = Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'database', 'provision')
        ->set('bindingForm.engine', 'postgres')
        ->call('confirmInstallDatabaseEngineOnServer')
        ->assertSet('showConfirmActionModal', true)
        ->call('confirmActionModal')
        ->assertSet('showConfirmActionModal', false)
        ->assertNotSet('onBoxEngineInstallRunId', null);

    expect($component->get('onBoxEngineInstallRunId'))->not->toBeEmpty();

    Queue::assertPushed(InstallDatabaseEngineJob::class);

    $row = ServerDatabaseEngine::query()
        ->where('server_id', $server->id)
        ->where('engine', 'postgres')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe(ServerDatabaseEngine::STATUS_PENDING);

    expect(ConsoleAction::query()
        ->where('subject_type', $row->getMorphClass())
        ->where('subject_id', $row->id)
        ->where('kind', 'db_engine_install')
        ->where('status', ConsoleAction::STATUS_QUEUED)
        ->exists())->toBeTrue();
});

test('on this server unlocks after the in-page engine install finishes', function () {
    [$user, $server, $site] = bindingModalSite();

    $row = ServerDatabaseEngine::query()->create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'status' => ServerDatabaseEngine::STATUS_RUNNING,
        'is_default' => true,
        'port' => 5432,
    ]);

    $run = ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => 'db_engine_install',
        'status' => ConsoleAction::STATUS_RUNNING,
        'label' => 'Installing PostgreSQL on this server',
        'user_id' => $user->id,
        'output' => ['v' => 1, 'lines' => []],
    ]);

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'database', 'provision')
        ->set('bindingForm.engine', 'postgres')
        ->set('bindingForm.placement', '')
        ->set('onBoxEngineInstallRunId', (string) $run->id)
        ->call('syncOnBoxDatabaseInstallProgress')
        ->assertSet('bindingForm.placement', 'on_box')
        ->assertSet('onBoxEngineInstallRunId', null);

    expect($row->fresh()?->status)->toBe(ServerDatabaseEngine::STATUS_RUNNING);
    expect($run->fresh()?->status)->toBe(ConsoleAction::STATUS_COMPLETED);
});

test('sqlite can live on this server without an install', function () {
    [$user, $server, $site] = bindingModalSite();

    $component = Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'database', 'provision')
        ->set('bindingForm.engine', 'sqlite');

    $onBox = collect($component->instance()->databasePlacements())->firstWhere('key', 'on_box');
    expect($onBox)->not->toBeNull()
        ->and($onBox['available'])->toBeTrue()
        ->and($onBox['install_action'])->toBeFalse();

    $component->assertSet('bindingForm.placement', 'on_box');
});
