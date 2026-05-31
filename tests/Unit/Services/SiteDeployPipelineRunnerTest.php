<?php

namespace Tests\Unit\Services\SiteDeployPipelineRunnerTest;

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
        public function publicResolve(SiteDeployStep $step): ?string
        {
            return $this->resolveShellCommand($step);
        }
    };

    expect($runner->publicResolve($step))->toBe($expected);
})->with('artisanInstallSteps');

test('artisan optimize includes no interaction flag', function () {
    expect(SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_ARTISAN_OPTIMIZE, ''))->toBe('php artisan optimize --no-interaction');
    expect(SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_YARN_INSTALL, ''))->toBe('yarn install --frozen-lockfile');
    expect(SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_ARTISAN_QUEUE_RESTART, ''))->toBe('php artisan queue:restart');
});
