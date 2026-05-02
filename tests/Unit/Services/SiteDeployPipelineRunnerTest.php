<?php

namespace Tests\Unit\Services;

use App\Models\SiteDeployStep;
use App\Services\Sites\SiteDeployPipelineCommands;
use App\Services\Sites\SiteDeployPipelineRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteDeployPipelineRunnerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function artisanInstallSteps(): iterable
    {
        yield 'octane' => [
            SiteDeployStep::TYPE_ARTISAN_OCTANE_INSTALL,
            'php artisan octane:install --no-interaction',
        ];
        yield 'reverb' => [
            SiteDeployStep::TYPE_ARTISAN_REVERB_INSTALL,
            'php artisan reverb:install --no-interaction',
        ];
    }

    #[DataProvider('artisanInstallSteps')]
    public function test_resolves_artisan_install_steps(string $type, string $expected): void
    {
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

        $this->assertSame($expected, $runner->publicResolve($step));
    }

    public function test_artisan_optimize_includes_no_interaction_flag(): void
    {
        $this->assertSame(
            'php artisan optimize --no-interaction',
            SiteDeployPipelineCommands::fragmentFor(SiteDeployStep::TYPE_ARTISAN_OPTIMIZE, '')
        );
    }
}
