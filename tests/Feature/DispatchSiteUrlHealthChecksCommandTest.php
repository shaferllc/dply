<?php

declare(strict_types=1);

namespace Tests\Feature\DispatchSiteUrlHealthChecksCommandTest;

use App\Jobs\CheckSiteUrlHealthJob;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('no op when disabled', function () {
    Config::set('dply.site_health_check_enabled', false);

    $exit = Artisan::call('dply:dispatch-site-url-health-checks');

    expect($exit)->toBe(0);
    $this->assertStringContainsString('disabled', Artisan::output());
});

test('queues health checks for active sites with domains using ulid ids', function () {
    Config::set('dply.site_health_check_enabled', true);
    Queue::fake();

    $user = User::factory()->create();
    $site = Site::factory()->create([
        'user_id' => $user->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'health.example.test',
        'is_primary' => true,
    ]);

    // Pending site without a domain must not be queued.
    Site::factory()->create([
        'user_id' => $user->id,
        'status' => Site::STATUS_PENDING,
    ]);

    $exit = Artisan::call('dply:dispatch-site-url-health-checks');

    expect($exit)->toBe(0);
    Queue::assertPushed(CheckSiteUrlHealthJob::class, function (CheckSiteUrlHealthJob $job) use ($site): bool {
        return $job->siteId === $site->id && is_string($job->siteId);
    });
    Queue::assertPushed(CheckSiteUrlHealthJob::class, 1);
});
