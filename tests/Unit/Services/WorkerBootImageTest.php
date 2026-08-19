<?php

declare(strict_types=1);

use App\Enums\ServerProvider;
use App\Jobs\CreateServerImageJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerImage;
use App\Models\User;
use App\Services\WorkerPools\WorkerBootImage;
use App\Support\Servers\ServerImageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function workerBakeServer(array $overrides = []): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
    ]);

    return Server::factory()->digitalOcean()->create(array_merge([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => '12345',
        'region' => 'sfo2',
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'meta' => [
            'server_role' => 'worker',
            'pool' => ['state' => 'provisioning'],
        ],
    ], $overrides));
}

it('returns a completed bake image for the same org, provider, and region', function () {
    $worker = workerBakeServer();
    ServerImage::query()->create([
        'server_id' => $worker->id,
        'organization_id' => $worker->organization_id,
        'user_id' => $worker->user_id,
        'provider' => ServerProvider::DigitalOcean->value,
        'name' => 'dply-worker-sfo2',
        'purpose' => ServerImage::PURPOSE_WORKER_BAKE,
        'status' => ServerImage::STATUS_COMPLETED,
        'provider_image_id' => '170999',
        'region' => 'sfo2',
    ]);

    $next = new Server([
        'organization_id' => $worker->organization_id,
        'provider' => ServerProvider::DigitalOcean,
        'region' => 'sfo2',
    ]);

    expect(app(WorkerBootImage::class)->providerImageIdFor($next))->toBe('170999');
});

it('does not reuse a DigitalOcean bake from another region', function () {
    $worker = workerBakeServer();
    ServerImage::query()->create([
        'server_id' => $worker->id,
        'organization_id' => $worker->organization_id,
        'provider' => ServerProvider::DigitalOcean->value,
        'name' => 'dply-worker-nyc1',
        'purpose' => ServerImage::PURPOSE_WORKER_BAKE,
        'status' => ServerImage::STATUS_COMPLETED,
        'provider_image_id' => '170111',
        'region' => 'nyc1',
    ]);

    $next = new Server([
        'organization_id' => $worker->organization_id,
        'provider' => ServerProvider::DigitalOcean,
        'region' => 'sfo2',
    ]);

    expect(app(WorkerBootImage::class)->providerImageIdFor($next))->toBeNull();
});

it('starts a snapshot before replay and queues the poll job', function () {
    Queue::fake();
    $worker = workerBakeServer();

    $images = Mockery::mock(ServerImageProvider::class);
    $images->shouldReceive('start')->once()->andReturn([
        'provider_image_id' => '',
        'provider_action_id' => '99',
        'region' => 'sfo2',
        'bytes' => null,
    ]);
    app()->instance(ServerImageProvider::class, $images);

    $image = app(WorkerBootImage::class)->captureBeforeReplay($worker);

    expect($image)->toBeInstanceOf(ServerImage::class)
        ->and($image->purpose)->toBe(ServerImage::PURPOSE_WORKER_BAKE)
        ->and($image->status)->toBe(ServerImage::STATUS_CREATING)
        ->and($image->provider_action_id)->toBe('99');

    Queue::assertPushed(CreateServerImageJob::class, fn (CreateServerImageJob $job): bool => $job->serverImageId === $image->id);
});

it('skips a second bake when an image is already ready', function () {
    Queue::fake();
    $worker = workerBakeServer();
    ServerImage::query()->create([
        'server_id' => $worker->id,
        'organization_id' => $worker->organization_id,
        'provider' => ServerProvider::DigitalOcean->value,
        'name' => 'existing',
        'purpose' => ServerImage::PURPOSE_WORKER_BAKE,
        'status' => ServerImage::STATUS_COMPLETED,
        'provider_image_id' => '170999',
        'region' => 'sfo2',
    ]);

    $images = Mockery::mock(ServerImageProvider::class);
    $images->shouldNotReceive('start');
    app()->instance(ServerImageProvider::class, $images);

    app(WorkerBootImage::class)->captureBeforeReplay($worker);

    Queue::assertNothingPushed();
});
