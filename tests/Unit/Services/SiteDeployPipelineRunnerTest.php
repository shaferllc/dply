<?php

namespace Tests\Unit\Services\SiteDeployPipelineRunnerTest;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Sites\SiteDeployPipelineCommands;
use App\Services\Sites\SiteDeployPipelineRunner;

/**
 * @return iterable<string, array{string, string}>
 */
dataset('artisanInstallSteps', function () {
    yield 'octane' => [
        SiteDeployStep::TYPE_ARTISAN_OCTANE_INSTALL,
        'php artisan octane:install --no-interaction',
    ];
    yield 'reverb' => [
        SiteDeployStep::TYPE_ARTISAN_REVERB_INSTALL,
        'php artisan reverb:install --no-interaction',
    ];
});

test('resolves artisan install steps', function (string $type, string $expected) {
    $step = new SiteDeployStep([
        'step_type' => $type,
        'custom_command' => null,
    ]);
    $runner = new class extends SiteDeployPipelineRunner
    {
        public function __construct()
        {
            // Bypass the parent constructor — resolveCommand() never touches the
            // injected DeployHookRunner, and constructing one here would drag in
            // the whole hook stack for a pure string-resolution test.
        }

        public function publicResolve(SiteDeployStep $step): ?string
        {
            return $this->resolveShellCommand($step);
        }
    };

    expect($runner->publicResolve($step))->toBe($expected);
})->with('artisanInstallSteps');

test('composer install prefix pins the site php binary ahead of the distro php', function () {
    $site = new Site([
        'runtime' => 'php',
        'runtime_version' => '8.4',
        'type' => SiteType::Php,
    ]);
    $step = new SiteDeployStep([
        'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
        'custom_command' => null,
    ]);
    $runner = new class extends SiteDeployPipelineRunner
    {
        public function __construct() {}

        public function publicPrefix(SiteDeployStep $step, string $cmd, Site $site): string
        {
            return $this->ensureToolingPrefix($step, $cmd, $site);
        }
    };

    $prefix = $runner->publicPrefix($step, 'composer install --no-dev', $site);

    expect($prefix)->toContain('/usr/bin/php8.4')
        ->and($prefix)->toContain('$HOME/.dply/bin')
        ->and($prefix)->toContain('composer not found');
});

test('artisan optimize includes no interaction flag', function () {
    expect(SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_ARTISAN_OPTIMIZE, ''))->toBe('php artisan optimize --no-interaction');
    expect(SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_YARN_INSTALL, ''))->toBe('yarn install --frozen-lockfile');
    expect(SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_ARTISAN_QUEUE_RESTART, ''))->toBe('php artisan queue:restart');
});

/**
 * The self-deploy Horizon guard.
 *
 * A restart command that terminates Horizon on the box Horizon is deploying
 * from kills the deploy worker mid-flight, which surfaces as an intermittent
 * "Deploy failed during the restart phase".
 */
function guardRunner(): object
{
    return new class extends SiteDeployPipelineRunner
    {
        public function __construct() {}

        public function publicGuard(Site $site, string $cmd, string &$log): string
        {
            return $this->guardSelfDeployHorizonTerminate($site, $cmd, $log);
        }
    };
}

function siteOnHost(bool $isLocal): Site
{
    $server = new class extends \App\Models\Server
    {
        public bool $local = false;

        public function isLocalDeployHost(): bool
        {
            return $this->local;
        }
    };
    $server->local = $isLocal;

    $site = new Site(['type' => SiteType::Php]);
    $site->setRelation('server', $server);

    return $site;
}

test('a bare horizon:terminate on a self-deploy is rewritten to the detached restart', function () {
    $log = '';
    $out = guardRunner()->publicGuard(siteOnHost(true), 'php artisan horizon:terminate', $log);

    expect($out)->toContain('dply:self-horizon-restart')
        ->and($out)->toContain('setsid')
        ->and($log)->toContain('rewrote');
});

test('the same command on a remote server is left completely alone', function () {
    $log = '';
    $cmd = 'php artisan horizon:terminate';
    $out = guardRunner()->publicGuard(siteOnHost(false), $cmd, $log);

    expect($out)->toBe($cmd)->and($log)->toBe('');
});

test('a compound command is warned about but never silently rewritten', function () {
    $log = '';
    $cmd = 'php artisan migrate --force && php artisan horizon:terminate';
    $out = guardRunner()->publicGuard(siteOnHost(true), $cmd, $log);

    expect($out)->toBe($cmd)->and($log)->toContain('WARNING');
});

test('unrelated restart commands are untouched', function () {
    $log = '';
    $cmd = 'php artisan queue:restart';
    $out = guardRunner()->publicGuard(siteOnHost(true), $cmd, $log);

    expect($out)->toBe($cmd)->and($log)->toBe('');
});
