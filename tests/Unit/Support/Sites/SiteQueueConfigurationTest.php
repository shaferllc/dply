<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites\SiteQueueConfigurationTest;

use App\Models\Site;
use App\Models\SiteBinding;
use App\Support\Sites\SiteQueueConfiguration;
use Illuminate\Database\Eloquent\Collection;

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

test('redis wins over database when the site has both', function () {
    // A site with Redis already provisioned should not be handed the database
    // driver — that adds queue churn to its primary database for no reason.
    $site = new Site;
    $site->forceFill(['id' => '01hzzzzzzzzzzzzzzzzzzzzzzz']);
    $site->setRelation('bindings', new Collection([
        tap(new SiteBinding, fn ($b) => $b->forceFill(['type' => 'database'])),
        tap(new SiteBinding, fn ($b) => $b->forceFill(['type' => 'redis'])),
    ]));

    expect(SiteQueueConfiguration::suggestedDriverFor($site))->toBe('redis');
});

test('database is the fallback, and nothing is offered when there is neither', function () {
    $with = new Site;
    $with->forceFill(['id' => 'a']);
    $with->setRelation('bindings', new Collection([
        tap(new SiteBinding, fn ($b) => $b->forceFill(['type' => 'database'])),
    ]));

    $without = new Site;
    $without->forceFill(['id' => 'b']);
    $without->setRelation('bindings', new Collection);

    expect(SiteQueueConfiguration::suggestedDriverFor($with))->toBe('database')
        // Offering a button that cannot work is worse than offering none.
        ->and(SiteQueueConfiguration::suggestedDriverFor($without))->toBeNull();
});

test('what the app reported beats what dply recorded', function () {
    // The real contradiction: a canary proved redis end to end while dply's
    // stored .env still read sync. A panel arguing with a green round trip is
    // worse than no panel.
    $site = siteWithEnv("QUEUE_CONNECTION=sync\n");
    $site->forceFill(['meta' => ['queue_observed' => ['driver' => 'redis', 'connection' => 'redis']]]);

    $config = SiteQueueConfiguration::for($site);

    expect($config->connection)->toBe('redis')
        ->and($config->isSync)->toBeFalse()
        ->and($config->warning())->toBeNull();
});

test('an observed sync still warns — the observation is trusted either way', function () {
    $site = siteWithEnv("QUEUE_CONNECTION=redis\n");
    $site->forceFill(['meta' => ['queue_observed' => ['driver' => 'sync']]]);

    expect(SiteQueueConfiguration::for($site)->isSync)->toBeTrue();
});
