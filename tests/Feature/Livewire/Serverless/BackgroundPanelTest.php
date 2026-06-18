<?php

namespace Tests\Feature\Livewire\Serverless\BackgroundPanelTest;

use App\Modules\Serverless\Livewire\BackgroundPanel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('toggle enables then disables background processing', function () {
    Livewire::actingAs($this->user)
        ->test(BackgroundPanel::class, ['site' => $this->site])
        ->call('toggle');
    expect(data_get($this->site->fresh()->meta, 'serverless.background_enabled'))->toBeTrue();

    Livewire::actingAs($this->user)
        ->test(BackgroundPanel::class, ['site' => $this->site])
        ->call('toggle');
    expect(data_get($this->site->fresh()->meta, 'serverless.background_enabled'))->toBeFalse();
});
