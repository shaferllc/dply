<?php

declare(strict_types=1);

namespace Tests\Feature\SiteTeardownRouterTest;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Cloud\Jobs\TeardownCloudSiteJob;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use App\Services\Sites\SiteTeardownRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function router(): SiteTeardownRouter
{
    return app(SiteTeardownRouter::class);
}

test('edge site is handed to the edge teardown instead of being deleted', function () {
    Queue::fake();

    $site = Site::factory()->create(['edge_backend' => 'cloudflare']);

    expect(router()->teardown($site))->toBeTrue();

    Queue::assertPushed(TeardownEdgeSiteJob::class, fn ($job) => $job->siteId === (string) $site->id);
    expect(Site::query()->whereKey($site->id)->exists())->toBeTrue(); // the job owns the delete
});

test('cloud site teardown is told to delete the row', function () {
    Queue::fake();

    $site = Site::factory()->create(['container_backend' => 'digitalocean_app_platform']);

    expect(router()->teardown($site))->toBeTrue();

    Queue::assertPushed(
        TeardownCloudSiteJob::class,
        fn ($job) => $job->siteId === (string) $site->id && $job->deleteSiteRow === true,
    );
});

test('serverless site is deleted through the function action', function () {
    Queue::fake();

    // No namespace in meta → byoNamespace() short-circuits, so nothing calls out
    // to DigitalOcean; the local rows still go.
    $server = Server::factory()->create(['meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS]]);
    $site = Site::factory()->create(['server_id' => $server->id]);

    expect(router()->teardown($site))->toBeTrue();
    expect(Site::query()->whereKey($site->id)->exists())->toBeFalse();
});

test('vm site is left for the caller to delete', function () {
    Queue::fake();

    $site = Site::factory()->create();

    expect(router()->teardown($site))->toBeFalse();

    Queue::assertNotPushed(TeardownEdgeSiteJob::class);
    Queue::assertNotPushed(TeardownCloudSiteJob::class);
    expect(Site::query()->whereKey($site->id)->exists())->toBeTrue();
});

test('scheduled deletion of a cloud site tears the container down', function () {
    Queue::fake();

    $site = Site::factory()->create([
        'container_backend' => 'digitalocean_app_platform',
        'scheduled_deletion_at' => now()->subMinute(),
    ]);

    $this->artisan('dply:process-scheduled-site-deletions')->assertSuccessful();

    Queue::assertPushed(
        TeardownCloudSiteJob::class,
        fn ($job) => $job->siteId === (string) $site->id && $job->deleteSiteRow === true,
    );
});
