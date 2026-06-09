<?php

namespace Tests\Feature\Livewire\Serverless\DatabasePanelTest;

use App\Jobs\ProvisionServerlessDatabaseJob;
use App\Livewire\Serverless\DatabasePanel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($this->user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);
    $this->site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $this->user->id,
    ]);
});

test('it shows the provision form when there is no database', function () {
    Livewire::actingAs($this->user)
        ->test(DatabasePanel::class, ['site' => $this->site])
        ->assertSee('Provision database');
});

test('provision records intent and dispatches the job', function () {
    Bus::fake();

    Livewire::actingAs($this->user)
        ->test(DatabasePanel::class, ['site' => $this->site])
        ->set('engine', 'pg')
        ->set('size', 'db-s-1vcpu-1gb')
        ->call('provision');

    Bus::assertDispatched(ProvisionServerlessDatabaseJob::class);
    expect(data_get($this->site->fresh()->meta, 'serverless.database.status'))->toBe('provisioning');
    expect(data_get($this->site->fresh()->meta, 'serverless.database.engine'))->toBe('pg');
});

test('it does not provision a second database', function () {
    Bus::fake();
    $this->site->forceFill([
        'meta' => ['serverless' => ['database' => ['status' => 'online', 'engine' => 'pg']]],
    ])->save();

    Livewire::actingAs($this->user)
        ->test(DatabasePanel::class, ['site' => $this->site])
        ->call('provision');

    Bus::assertNotDispatched(ProvisionServerlessDatabaseJob::class);
});
