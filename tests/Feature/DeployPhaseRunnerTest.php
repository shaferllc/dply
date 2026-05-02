<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Deploy\DeployPhaseRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeployPhaseRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_build_walks_build_steps_in_order_and_executes_in_release_dir(): void
    {
        [$site] = $this->makeSite(['runtime' => 'php']);

        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 10,
            'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
            'phase' => SiteDeployStep::PHASE_BUILD,
            'timeout_seconds' => 600,
        ]);
        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 20,
            'step_type' => SiteDeployStep::TYPE_NPM_RUN,
            'custom_command' => 'build',
            'phase' => SiteDeployStep::PHASE_BUILD,
            'timeout_seconds' => 600,
        ]);

        $shell = new DeployRecordingShell;
        $results = (new DeployPhaseRunner)->runBuild(
            $site,
            '/var/www/app/releases/01HXX',
            fn () => $shell,
        );

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['ok']);
        $this->assertSame(SiteDeployStep::TYPE_COMPOSER_INSTALL, $results[0]['step_type']);

        // Both commands ran inside the release dir.
        foreach ($shell->execCalls as $call) {
            $this->assertStringContainsString("cd '/var/www/app/releases/01HXX'", $call);
        }
        // The first command is composer install --no-dev --optimize-autoloader.
        $this->assertStringContainsString('composer install --no-dev --optimize-autoloader', $shell->execCalls[0]);
        // The second command is npm run build.
        $this->assertStringContainsString('npm run build', $shell->execCalls[1]);
    }

    public function test_run_build_aborts_on_first_failure(): void
    {
        [$site] = $this->makeSite(['runtime' => 'php']);

        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 10,
            'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
            'phase' => SiteDeployStep::PHASE_BUILD,
            'timeout_seconds' => 600,
        ]);
        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 20,
            'step_type' => SiteDeployStep::TYPE_ARTISAN_OPTIMIZE,
            'phase' => SiteDeployStep::PHASE_BUILD,
            'timeout_seconds' => 120,
        ]);

        $shell = new DeployRecordingShell;
        $shell->failOn = 'composer install';

        $results = (new DeployPhaseRunner)->runBuild(
            $site,
            '/var/www/app/releases/01HXX',
            fn () => $shell,
        );

        // Only one result — second step never ran.
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['ok']);
        $this->assertSame(1, count($shell->execCalls));
    }

    public function test_run_build_skips_custom_step_with_blank_command(): void
    {
        [$site] = $this->makeSite(['runtime' => 'go']);

        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 10,
            'step_type' => SiteDeployStep::TYPE_CUSTOM,
            'custom_command' => '',
            'phase' => SiteDeployStep::PHASE_BUILD,
            'timeout_seconds' => 60,
        ]);
        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 20,
            'step_type' => SiteDeployStep::TYPE_CUSTOM,
            'custom_command' => 'go build -o bin/app ./...',
            'phase' => SiteDeployStep::PHASE_BUILD,
            'timeout_seconds' => 600,
        ]);

        $shell = new DeployRecordingShell;
        $results = (new DeployPhaseRunner)->runBuild(
            $site,
            '/var/www/app/releases/01HXX',
            fn () => $shell,
        );

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['ok']);
        $this->assertTrue(($results[0]['skipped'] ?? false));
        $this->assertSame(1, count($shell->execCalls));
        $this->assertStringContainsString('go build', $shell->execCalls[0]);
    }

    public function test_run_swap_skips_when_deploys_are_not_atomic(): void
    {
        [$site] = $this->makeSite(['deploy_strategy' => 'simple']);

        $shell = new DeployRecordingShell;
        $result = (new DeployPhaseRunner)->runSwap(
            $site,
            '/var/www/app/releases/01HXX',
            fn () => $shell,
        );

        $this->assertSame([], $result);
        $this->assertSame([], $shell->execCalls);
    }

    public function test_run_swap_flips_current_symlink_for_atomic_deploys(): void
    {
        [$site] = $this->makeSite([
            'runtime' => 'node',
            'deploy_strategy' => 'atomic',
            'repository_path' => '/var/www/jobs',
        ]);

        $shell = new DeployRecordingShell;
        $results = (new DeployPhaseRunner)->runSwap(
            $site,
            '/var/www/jobs/releases/01HXX',
            fn () => $shell,
        );

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['ok']);
        $this->assertSame(1, count($shell->execCalls));
        $this->assertStringContainsString("ln -sfn '/var/www/jobs/releases/01HXX' '/var/www/jobs/current'", $shell->execCalls[0]);
    }

    public function test_run_release_uses_current_symlink_for_atomic_deploys(): void
    {
        [$site] = $this->makeSite([
            'runtime' => 'php',
            'deploy_strategy' => 'atomic',
            'repository_path' => '/var/www/laravel',
        ]);
        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 10,
            'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
            'phase' => SiteDeployStep::PHASE_RELEASE,
            'timeout_seconds' => 300,
        ]);

        $shell = new DeployRecordingShell;
        (new DeployPhaseRunner)->runRelease(
            $site,
            '/var/www/laravel/releases/01HXX',
            fn () => $shell,
        );

        $this->assertStringContainsString("cd '/var/www/laravel/current'", $shell->execCalls[0]);
        $this->assertStringContainsString('php artisan migrate --force', $shell->execCalls[0]);
    }

    public function test_run_restart_reloads_php_fpm_for_php_sites(): void
    {
        [$site] = $this->makeSite([
            'runtime' => 'php',
            'runtime_version' => '8.4',
        ]);

        $shell = new DeployRecordingShell;
        $results = (new DeployPhaseRunner)->runRestart($site, fn () => $shell);

        $this->assertCount(1, $results);
        $this->assertStringContainsString('sudo systemctl reload php8.4-fpm', $shell->execCalls[0]);
    }

    public function test_run_restart_restarts_systemd_unit_for_node_sites(): void
    {
        [$site] = $this->makeSite([
            'runtime' => 'node',
            'start_command' => 'npm start',
        ]);

        $shell = new DeployRecordingShell;
        $results = (new DeployPhaseRunner)->runRestart($site, fn () => $shell);

        $this->assertCount(1, $results);
        $this->assertStringContainsString('sudo systemctl restart', $shell->execCalls[0]);
        $this->assertStringContainsString($site->id, $shell->execCalls[0]);
    }

    public function test_run_restart_is_a_noop_for_static_sites(): void
    {
        [$site] = $this->makeSite(['runtime' => 'static']);

        $shell = new DeployRecordingShell;
        $results = (new DeployPhaseRunner)->runRestart($site, fn () => $shell);

        $this->assertSame([], $results);
        $this->assertSame([], $shell->execCalls);
    }

    public function test_runner_throws_when_server_is_not_ready(): void
    {
        $server = Server::factory()->create([
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => null,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'node',
            'start_command' => 'npm start',
        ]);
        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 10,
            'step_type' => SiteDeployStep::TYPE_NPM_CI,
            'phase' => SiteDeployStep::PHASE_BUILD,
            'timeout_seconds' => 600,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server must be ready');

        (new DeployPhaseRunner)->runBuild(
            $site,
            '/var/www/app/releases/01HXX',
            fn () => new DeployRecordingShell,
        );
    }

    public function test_runner_returns_empty_for_phase_with_no_steps(): void
    {
        [$site] = $this->makeSite(['runtime' => 'php']);

        $shell = new DeployRecordingShell;
        $result = (new DeployPhaseRunner)->runBuild(
            $site,
            '/var/www/app/releases/01HXX',
            fn () => $shell,
        );

        $this->assertSame([], $result);
        $this->assertSame([], $shell->execCalls);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: Site}
     */
    private function makeSite(array $overrides = []): array
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'runtime' => 'php',
        ], $overrides));

        return [$site];
    }
}

class DeployRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $execCalls = [];

    public ?string $failOn = null;

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->execCalls[] = $command;
        if ($this->failOn !== null && str_contains($command, $this->failOn)) {
            throw new \RuntimeException('Simulated step failure: '.$this->failOn);
        }

        return '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
    }
}
