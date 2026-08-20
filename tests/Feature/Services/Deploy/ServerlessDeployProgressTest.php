<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy\ServerlessDeployProgressTest;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Deploy\Services\ServerlessDeployProgress;
use App\Modules\Serverless\Exceptions\ServerlessDeployCancelledException;
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

test('seed writes the pending catalog once', function () {
    $site = Site::factory()->create();
    $deployment = runningDeployment($site);

    $progress = new ServerlessDeployProgress;
    $progress->seed($site);
    $progress->seed($site);

    $steps = $deployment->fresh()->phaseSteps(ServerlessDeployProgress::PHASE);

    expect($steps)->toHaveCount(count(ServerlessDeployProgress::CATALOG));
    expect(collect($steps)->pluck('key')->all())->toBe(array_column(ServerlessDeployProgress::CATALOG, 'key'));
    expect(collect($steps)->every(fn (array $step): bool => $step['state'] === 'pending'))->toBeTrue();
});

test('append log streams a tail onto the running deployment', function () {
    $site = Site::factory()->create();
    $deployment = runningDeployment($site);

    $progress = new ServerlessDeployProgress;
    $progress->appendLog($site, "Installing dependencies\n");
    $progress->appendLog($site, '- Installing laravel/framework');
    $progress->flushLog($site);

    expect($deployment->fresh()->log_output)
        ->toContain('Installing dependencies')
        ->toContain('Installing laravel/framework');
});

test('dependencies label names composer and npm', function () {
    expect(ServerlessDeployProgress::dependenciesLabel('composer install --no-dev --optimize-autoloader'))
        ->toBe('Installing Composer dependencies');
    expect(ServerlessDeployProgress::dependenciesLabel('npm ci && npm run build'))
        ->toBe('Installing Node dependencies');
    expect(ServerlessDeployProgress::dependenciesLabel('composer install && npm run build'))
        ->toBe('Installing Composer and Node dependencies');
});
