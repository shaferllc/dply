<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy\ServerlessDeployProgressTest;

use App\Modules\Serverless\Exceptions\ServerlessDeployCancelledException;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Deploy\ServerlessDeployProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runningDeployment(Site $site): SiteDeployment
{
    return SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now(),
    ]);
}
test('it upserts steps into the running deployment', function () {
    $site = Site::factory()->create();
    $deployment = runningDeployment($site);

    $progress = new ServerlessDeployProgress;
    $progress->active($site, 'checkout', 'Cloning repository');
    $progress->done($site, 'checkout', 'Cloned repository');
    $progress->active($site, 'upload', 'Uploading to DigitalOcean Functions');

    $steps = $deployment->fresh()->phaseSteps(ServerlessDeployProgress::PHASE);

    expect($steps)->toHaveCount(2, 'checkout should upsert, not append');
    expect($steps[0]['state'])->toBe('done');
    expect($steps[0]['label'])->toBe('Cloned repository');
    expect($steps[0]['ok'])->toBeTrue();
    expect($steps[0]['duration_ms'])->toBeInt('a finished step records its duration');
    expect($steps[1]['state'])->toBe('active');
    expect($steps[1]['ok'])->toBeFalse();
    expect($steps[1]['duration_ms'])->toBeNull('an in-flight step has no duration yet');
});
test('it is a no op without a running deployment', function () {
    $site = Site::factory()->create();

    (new ServerlessDeployProgress)->active($site, 'checkout', 'Cloning repository');

    expect(SiteDeployment::query()->count())->toBe(0);
});
test('it ignores a finished deployment', function () {
    $site = Site::factory()->create();
    $finished = runningDeployment($site);
    $finished->update(['status' => SiteDeployment::STATUS_SUCCESS, 'finished_at' => now()]);

    (new ServerlessDeployProgress)->active($site, 'checkout', 'Cloning repository');

    expect($finished->fresh()->phaseSteps(ServerlessDeployProgress::PHASE))->toBe([]);
});
test('checkpoint aborts when cancellation is requested', function () {
    $site = Site::factory()->create();
    $deployment = runningDeployment($site);

    $progress = new ServerlessDeployProgress;
    $progress->requestCancel($site, $deployment->id);

    $this->expectException(ServerlessDeployCancelledException::class);
    $progress->checkpoint($site);
});
test('checkpoint is a no op without a cancel request', function () {
    $site = Site::factory()->create();
    runningDeployment($site);

    (new ServerlessDeployProgress)->checkpoint($site);

    $this->expectNotToPerformAssertions();
});
test('checkpoint ignores a stale request for a different deployment', function () {
    $site = Site::factory()->create();
    $current = runningDeployment($site);

    // A cancel request left over from an earlier deployment must not
    // abort the current run.
    (new ServerlessDeployProgress)->requestCancel($site, 'an-old-deployment-id');
    (new ServerlessDeployProgress)->checkpoint($site);

    expect($current->fresh()->status)->toBe(SiteDeployment::STATUS_RUNNING);
});
