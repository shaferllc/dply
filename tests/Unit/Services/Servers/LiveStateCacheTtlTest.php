<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Servers\LiveState\AbstractEngineLiveStateProbe;
use App\Services\Servers\LiveState\EngineLiveState;
use Carbon\CarbonImmutable;

test('live state cache expires after configured ttl', function () {
    config(['server_manage.webserver_live_state_cache_seconds' => 60]);

    $server = Server::factory()->make([
        'meta' => [
            'webserver_live_state' => [
                'nginx' => (new EngineLiveState(
                    engine: 'nginx',
                    capturedAt: CarbonImmutable::now()->subSeconds(120),
                    isFresh: true,
                    units: ['hosts' => [['server_names' => ['old.test']]]],
                ))->toArray(),
            ],
        ],
    ]);

    $probe = new class extends AbstractEngineLiveStateProbe
    {
        public int $freshCalls = 0;

        public function engineKey(): string
        {
            return 'nginx';
        }

        protected function runFreshProbe(Server $server): EngineLiveState
        {
            $this->freshCalls++;

            return new EngineLiveState(
                engine: 'nginx',
                capturedAt: CarbonImmutable::now(),
                isFresh: true,
                units: ['hosts' => []],
            );
        }
    };

    $state = $probe->probe($server, forceFresh: false);

    expect($probe->freshCalls)->toBe(1)
        ->and($state->units['hosts'])->toBe([]);
});

test('live state cache is reused within ttl', function () {
    config(['server_manage.webserver_live_state_cache_seconds' => 60]);

    $server = Server::factory()->make([
        'meta' => [
            'webserver_live_state' => [
                'nginx' => (new EngineLiveState(
                    engine: 'nginx',
                    capturedAt: CarbonImmutable::now()->subSeconds(10),
                    isFresh: true,
                    units: ['hosts' => [['server_names' => ['cached.test']]]],
                ))->toArray(),
            ],
        ],
    ]);

    $probe = new class extends AbstractEngineLiveStateProbe
    {
        public int $freshCalls = 0;

        public function engineKey(): string
        {
            return 'nginx';
        }

        protected function runFreshProbe(Server $server): EngineLiveState
        {
            $this->freshCalls++;

            return new EngineLiveState(
                engine: 'nginx',
                capturedAt: CarbonImmutable::now(),
                isFresh: true,
                units: ['hosts' => []],
            );
        }
    };

    $state = $probe->probe($server, forceFresh: false);

    expect($probe->freshCalls)->toBe(0)
        ->and($state->units['hosts'][0]['server_names'])->toBe(['cached.test'])
        ->and($state->isFresh)->toBeFalse();
});
