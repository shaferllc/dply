<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites\SiteQueueConfigurationTest;

use App\Models\Site;
use App\Support\Sites\SiteQueueConfiguration;

function siteWithEnv(string $env): Site
{
    $site = new Site;
    $site->forceFill(['id' => '01hzzzzzzzzzzzzzzzzzzzzzzz', 'env_file_content' => $env]);

    return $site;
}

test('sync is reported as broken, because a worker against it consumes nothing', function () {
    $config = SiteQueueConfiguration::for(siteWithEnv("APP_ENV=production\nQUEUE_CONNECTION=sync\n"));

    expect($config->isSync)->toBeTrue()
        ->and($config->isConfigured)->toBeFalse()
        ->and($config->warning())->toContain('inline');
});

test('an absent QUEUE_CONNECTION is the broken case, not an unknown one', function () {
    // Laravel's own default when the key is missing is sync, so silence here
    // would hide exactly the failure this class exists to catch.
    $config = SiteQueueConfiguration::for(siteWithEnv("APP_ENV=production\n"));

    expect($config->isSync)->toBeTrue()
        ->and($config->warning())->not->toBeNull();
});

test('real drivers pass silently, quoted or not', function () {
    foreach (['redis', '"redis"', "'database'", 'sqs'] as $value) {
        $config = SiteQueueConfiguration::for(siteWithEnv("QUEUE_CONNECTION={$value}\n"));

        expect($config->isConfigured)->toBeTrue()
            ->and($config->isSync)->toBeFalse()
            ->and($config->warning())->toBeNull();
    }
});

test('an unrecognised driver warns without claiming to know better', function () {
    $config = SiteQueueConfiguration::for(siteWithEnv("QUEUE_CONNECTION=carrier-pigeon\n"));

    expect($config->isConfigured)->toBeFalse()
        ->and($config->isSync)->toBeFalse()
        ->and($config->warning())->toContain('carrier-pigeon');
});
