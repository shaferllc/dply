<?php

declare(strict_types=1);

use App\Jobs\ConvertSiteToAtomicLayoutJob;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Sites\SiteAtomicLayoutRequester;
use App\Services\Sites\SiteDeployCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('request atomic without confirm does not flip strategy', function () {
    $site = Site::factory()->create(['deploy_strategy' => 'simple']);

    $result = app(SiteAtomicLayoutRequester::class)->requestAtomic($site, null, confirmed: false);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe('needs_confirm')
        ->and($site->fresh()->deploy_strategy)->toBe('simple')
        ->and($site->fresh()->isConvertingAtomicLayout())->toBeFalse();
});

test('confirmed request atomic queues convert and stays simple', function () {
    Queue::fake();
    $site = Site::factory()->create(['deploy_strategy' => 'simple']);

    $result = app(SiteAtomicLayoutRequester::class)->requestAtomic($site, null, confirmed: true);

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe('queued')
        ->and($site->fresh()->deploy_strategy)->toBe('simple')
        ->and($site->fresh()->isConvertingAtomicLayout())->toBeTrue();

    Queue::assertPushed(ConvertSiteToAtomicLayoutJob::class);
});

test('request atomic refuses while a deploy is running', function () {
    $site = Site::factory()->create(['deploy_strategy' => 'simple']);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'status' => SiteDeployment::STATUS_RUNNING,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'started_at' => now(),
    ]);

    $result = app(SiteAtomicLayoutRequester::class)->requestAtomic($site, null, confirmed: true);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe('refused')
        ->and($site->fresh()->isConvertingAtomicLayout())->toBeFalse();
});

test('request flat arms disable and keeps atomic', function () {
    $site = Site::factory()->create(['deploy_strategy' => 'atomic']);

    $result = app(SiteAtomicLayoutRequester::class)->requestFlat($site);

    $site->refresh();

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe('armed')
        ->and($site->deploy_strategy)->toBe('atomic')
        ->and($site->isDisablingAtomicLayout())->toBeTrue();
});

test('coordinator treats converting sites as in progress', function () {
    $site = Site::factory()->create([
        'deploy_strategy' => 'simple',
        'meta' => ['atomic_layout' => ['status' => 'converting']],
    ]);

    expect(app(SiteDeployCoordinator::class)->inProgress($site))->toBeTrue();
});
