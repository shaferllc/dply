<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Serverless\NetworkPanelTest;

use App\Models\Organization;
use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Livewire\NetworkPanel;
use App\Modules\Serverless\Services\ServerlessNetworkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
usesFeatures('surface.serverless');

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $this->org->id]);

    $server = Server::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'region' => 'nyc3',
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $this->site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $this->org->id,
        'user_id' => $this->user->id,
        'meta' => ['serverless' => []],
    ]);

    $this->network = PrivateNetwork::query()->create([
        'organization_id' => $this->org->id,
        'provider' => PrivateNetwork::PROVIDER_DO,
        'provider_id' => 'vpc-uuid-nyc3',
        'name' => 'default-nyc3',
        'ip_range' => '10.116.0.0/20',
        'network_zone' => 'nyc3',
    ]);
});

test('attaching a network records it and drives cluster placement', function () {
    Livewire::actingAs($this->user)
        ->test(NetworkPanel::class, ['site' => $this->site])
        ->set('networkId', $this->network->id)
        ->call('attach');

    $site = $this->site->fresh();

    expect($site->serverlessConfig()['network_id'])->toBe($this->network->id)
        ->and(app(ServerlessNetworkService::class)->vpcUuid($site))->toBe('vpc-uuid-nyc3');
});

test('detaching clears the attachment so DigitalOcean picks the default VPC', function () {
    app(ServerlessNetworkService::class)->attach($this->site, $this->network);

    Livewire::actingAs($this->user)
        ->test(NetworkPanel::class, ['site' => $this->site->fresh()])
        ->set('networkId', '')
        ->call('attach');

    $site = $this->site->fresh();

    expect($site->serverlessConfig())->not->toHaveKey('network_id')
        ->and(app(ServerlessNetworkService::class)->vpcUuid($site))->toBeNull();
});

test('a network in another region is not attachable', function () {
    $elsewhere = PrivateNetwork::query()->create([
        'organization_id' => $this->org->id,
        'provider' => PrivateNetwork::PROVIDER_DO,
        'provider_id' => 'vpc-uuid-fra1',
        'name' => 'default-fra1',
        'network_zone' => 'fra1',
    ]);

    Livewire::actingAs($this->user)
        ->test(NetworkPanel::class, ['site' => $this->site])
        ->set('networkId', $elsewhere->id)
        ->call('attach');

    expect($this->site->fresh()->serverlessConfig())->not->toHaveKey('network_id');
});

test('a database created before the attachment is reported as outside the network', function () {
    $networks = app(ServerlessNetworkService::class);
    $site = $this->site;

    $site->forceFill(['meta' => ['serverless' => [
        'database' => ['status' => 'online', 'cluster_id' => 'db-1'],
    ]]])->save();

    $networks->attach($site->fresh(), $this->network);

    expect($networks->hasClustersOutsideNetwork($site->fresh()))->toBeTrue();
});
