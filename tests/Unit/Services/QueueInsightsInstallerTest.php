<?php

declare(strict_types=1);

namespace Tests\Unit\Services\QueueInsightsInstallerTest;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Services\Sites\QueueInsightsInstaller;

function shell(array $responses): RemoteShell
{
    return new class($responses) implements RemoteShell
    {
        public array $ran = [];

        public function __construct(private array $responses) {}

        public function exec(string $command, int $timeoutSeconds = 120): string
        {
            $this->ran[] = $command;

            return array_shift($this->responses) ?? '';
        }

        public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void {}
    };
}

function site(bool $optedIn): Site
{
    $s = new Site;
    $s->forceFill(['id' => '01hzzzzzzzzzzzzzzzzzzzzzzz', 'meta' => ['queue_insights' => ['enabled' => $optedIn]]]);

    return $s;
}

beforeEach(fn () => config()->set('dply.queue_insights', [
    'enabled' => true, 'package' => 'dply/queue-insights', 'constraint' => '^1.0',
]));

test('a site that has not opted in is never touched', function () {
    // Writing to a customer's composer.json is their decision, not dply's.
    $ssh = shell([]);

    expect((new QueueInsightsInstaller)->ensure(site(optedIn: false), $ssh, '/srv/app'))->toBeNull()
        ->and($ssh->ran)->toBe([]);
});

test('the platform switch overrides the per-site opt-in', function () {
    config()->set('dply.queue_insights.enabled', false);
    $ssh = shell([]);

    expect((new QueueInsightsInstaller)->ensure(site(optedIn: true), $ssh, '/srv/app'))->toBeNull()
        ->and($ssh->ran)->toBe([]);
});

test('an already-installed package costs one check and no resolution', function () {
    // Otherwise every deploy pays a composer resolve it does not need.
    $ssh = shell(['dply/queue-insights 1.0.0 Reports queue events']);

    expect((new QueueInsightsInstaller)->ensure(site(optedIn: true), $ssh, '/srv/app'))->toBeNull()
        ->and($ssh->ran)->toHaveCount(1)
        ->and($ssh->ran[0])->toContain('composer show');
});

test('a missing package is required with scripts disabled', function () {
    $ssh = shell(['', 'Installing dply/queue-insights (1.0.0)']);
    $note = (new QueueInsightsInstaller)->ensure(site(optedIn: true), $ssh, '/srv/app');

    expect($note)->toContain('installed dply/queue-insights')
        ->and($ssh->ran[1])->toContain('composer require')
        // Package scripts in a customer app can do anything; a deploy is not
        // the place to find out what.
        ->and($ssh->ran[1])->toContain('--no-scripts')
        ->and($ssh->ran[1])->toContain('|| true');
});

test('a failed install reports and explicitly does not fail the deploy', function () {
    $ssh = shell(['', 'Could not find package dply/queue-insights']);
    $note = (new QueueInsightsInstaller)->ensure(site(optedIn: true), $ssh, '/srv/app');

    expect($note)->toContain('could not install')
        ->and($note)->toContain('the deploy was not affected')
        // The point of the agent is extra detail; without it the page still works.
        ->and($note)->toContain('Depth and failures still work without it');
});

test('an SSH failure is reported, never thrown', function () {
    $ssh = new class implements RemoteShell
    {
        public function exec(string $command, int $timeoutSeconds = 120): string
        {
            throw new \RuntimeException('connection reset');
        }

        public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void {}
    };

    expect((new QueueInsightsInstaller)->ensure(site(optedIn: true), $ssh, '/srv/app'))
        ->toContain('connection reset');
});
