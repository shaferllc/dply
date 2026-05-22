<?php

declare(strict_types=1);

namespace Tests\Feature\CloudWorkerModelTest;
use App\Models\CloudWorker;
use App\Models\Site;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('factory creates a provisioning queue worker', function () {
    $worker = CloudWorker::factory()->create();

    expect($worker->type)->toBe(CloudWorker::TYPE_WORKER);
    expect($worker->status)->toBe(CloudWorker::STATUS_PROVISIONING);
    expect($worker->isScheduler())->toBeFalse();
    expect($worker->isActive())->toBeFalse();
});
test('scheduler factory state', function () {
    $worker = CloudWorker::factory()->scheduler()->create();

    expect($worker->type)->toBe(CloudWorker::TYPE_SCHEDULER);
    expect($worker->isScheduler())->toBeTrue();
});
test('worker effective command uses stored command', function () {
    $worker = CloudWorker::factory()->make(['command' => 'php artisan horizon']);

    expect($worker->effectiveCommand())->toBe('php artisan horizon');
});
test('worker effective command falls back to default', function () {
    $worker = CloudWorker::factory()->make(['type' => CloudWorker::TYPE_WORKER, 'command' => '']);

    expect($worker->effectiveCommand())->toBe('php artisan queue:work');
});
test('scheduler effective command is always schedule work', function () {
    // Even with a bogus stored command, the scheduler runs schedule:work.
    $worker = CloudWorker::factory()->make([
        'type' => CloudWorker::TYPE_SCHEDULER,
        'command' => 'php artisan queue:work',
    ]);

    expect($worker->effectiveCommand())->toBe('php artisan schedule:work');
});
test('scheduler effective instance count is always one', function () {
    $worker = CloudWorker::factory()->make([
        'type' => CloudWorker::TYPE_SCHEDULER,
        'instance_count' => 5,
    ]);

    expect($worker->effectiveInstanceCount())->toBe(1);
});
test('worker effective instance count respects stored value', function () {
    $worker = CloudWorker::factory()->make(['type' => CloudWorker::TYPE_WORKER, 'instance_count' => 4]);

    expect($worker->effectiveInstanceCount())->toBe(4);
});
test('worker effective instance count floors at one', function () {
    $worker = CloudWorker::factory()->make(['type' => CloudWorker::TYPE_WORKER, 'instance_count' => 0]);

    expect($worker->effectiveInstanceCount())->toBe(1);
});
test('size tier maps to do size slug', function () {
    expect(CloudWorker::factory()->make(['size' => 'small'])->backendSizeSlug())->toBe('basic-xxs');
    expect(CloudWorker::factory()->make(['size' => 'medium'])->backendSizeSlug())->toBe('basic-xs');
    expect(CloudWorker::factory()->make(['size' => 'large'])->backendSizeSlug())->toBe('basic-s');
    expect(CloudWorker::factory()->make(['size' => 'xlarge'])->backendSizeSlug())->toBe('basic-m');
    expect(CloudWorker::factory()->make(['size' => 'bogus'])->backendSizeSlug())->toBe('basic-xxs');
});
test('site relation', function () {
    $site = Site::factory()->create();
    $worker = CloudWorker::factory()->create(['site_id' => $site->id]);

    expect($worker->site->is($site))->toBeTrue();
});
