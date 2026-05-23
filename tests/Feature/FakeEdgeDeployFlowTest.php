<?php

declare(strict_types=1);

namespace Tests\Feature\FakeEdgeDeployFlowTest;

use App\Actions\Edge\CreateEdgeSite;
use App\Jobs\BuildEdgeSiteJob;
use App\Jobs\PublishEdgeDeploymentJob;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Edge\EdgeRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('fake edge create runs build and publish to live deployment', function () {
    config(['edge.fake.enabled' => true]);
    [$user, $org] = scaffold();

    $site = (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'Fake Flow',
        'repo' => 'acme/static',
        'branch' => 'main',
    ]);

    $deployment = EdgeDeployment::query()->where('site_id', $site->id)->latest()->first();
    Queue::assertPushed(BuildEdgeSiteJob::class);

    // Queue::fake() is applied globally to feature tests via FakesRemoteServerAccess.
    // Drive the chain inline so the fake-edge backend can mark the site live.
    app()->call([new BuildEdgeSiteJob($deployment->id), 'handle']);
    foreach (Queue::pushed(PublishEdgeDeploymentJob::class) as $job) {
        app()->call([$job, 'handle']);
    }

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_EDGE_ACTIVE);
    expect($site->edgeMeta()['active_deployment_id'] ?? null)->not->toBeNull();
    expect($site->edgeLiveUrl())->toStartWith('https://');

    $deployment = EdgeDeployment::query()->find($site->edgeMeta()['active_deployment_id']);
    expect($deployment)->not->toBeNull();
    expect($deployment->status)->toBe(EdgeDeployment::STATUS_LIVE);
    expect($deployment->published_at)->not->toBeNull();

    $backend = EdgeRouter::backendFor($site);
    expect($backend)->not->toBeNull();
});

/**
 * @return array{0: User, 1: Organization}
 */
function scaffold(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return [$user, $org];
}
