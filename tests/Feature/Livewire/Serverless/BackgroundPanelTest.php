<?php

namespace Tests\Feature\Livewire\Serverless\BackgroundPanelTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Deploy\Services\ServerlessEnvironmentPreparer;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Serverless\Livewire\BackgroundPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
usesFeatures('surface.serverless', 'surface.queue');

/** The platform half of the provisioner's two-key gate. */
function configureDplyQueue(): void
{
    config([
        'queue_service.enabled' => true,
        'queue_service.public_url' => 'https://queue.dply.test/api/queue/v1',
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

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

test('the blocked banner leads with dply Queue', function () {
    // An unset QUEUE_CONNECTION is inert, so the pump refuses to drain and the
    // panel has to say what to do about it.
    configureDplyQueue();
    $this->site->forceFill(['meta' => ['serverless' => ['background_enabled' => true]]])->save();

    Livewire::actingAs($this->user)
        ->test(BackgroundPanel::class, ['site' => $this->site])
        ->assertSee('Use dply Queue')
        ->assertSee('Queued jobs will not run');
});

test('the banner falls back to Redis wording when dply Queue is off', function () {
    config(['queue_service.enabled' => false]);
    $this->site->forceFill(['meta' => ['serverless' => ['background_enabled' => true]]])->save();

    Livewire::actingAs($this->user)
        ->test(BackgroundPanel::class, ['site' => $this->site])
        ->assertDontSee('Use dply Queue')
        ->assertSee('Provision a Redis cache for this function');
});

test('useDplyQueue creates the namespace and wires the env', function () {
    configureDplyQueue();

    Livewire::actingAs($this->user)
        ->test(BackgroundPanel::class, ['site' => $this->site])
        ->call('useDplyQueue');

    $fresh = $this->site->fresh();
    expect(QueueNamespace::query()->where('site_id', $this->site->id)->count())->toBe(1);
    expect((string) $fresh->env_file_content)->toContain('QUEUE_CONNECTION=dply');
    expect((string) $fresh->env_file_content)->toContain('DPLY_QUEUE_SECRET=');
});

test('useDplyQueue provisions nothing when the platform is not configured', function () {
    config(['queue_service.enabled' => false]);

    Livewire::actingAs($this->user)
        ->test(BackgroundPanel::class, ['site' => $this->site])
        ->call('useDplyQueue');

    expect(QueueNamespace::query()->where('site_id', $this->site->id)->count())->toBe(0);
    expect((string) $this->site->fresh()->env_file_content)->not->toContain('DPLY_QUEUE_URL');
});

test('the panel shows the managed queue endpoint and depth once wired', function () {
    configureDplyQueue();

    Livewire::actingAs($this->user)
        ->test(BackgroundPanel::class, ['site' => $this->site])
        ->call('useDplyQueue');

    $namespace = QueueNamespace::query()->where('site_id', $this->site->id)->firstOrFail();

    Livewire::actingAs($this->user)
        ->test(BackgroundPanel::class, ['site' => $this->site->fresh()])
        ->assertSee('dply Queue')
        ->assertSee('https://queue.dply.test/api/queue/v1/'.$namespace->id)
        ->assertSee('0 pending');
});

test('the panel warns when the connection has been pointed away from a live namespace', function () {
    configureDplyQueue();

    Livewire::actingAs($this->user)
        ->test(BackgroundPanel::class, ['site' => $this->site])
        ->call('useDplyQueue');

    app(ServerlessEnvironmentPreparer::class)
        ->mergeKeys($this->site->fresh(), ['QUEUE_CONNECTION' => 'sqs']);

    Livewire::actingAs($this->user)
        ->test(BackgroundPanel::class, ['site' => $this->site->fresh()])
        ->assertSee('not being used');
});
