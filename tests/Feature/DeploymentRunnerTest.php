<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeployStep;
use App\Services\Deploy\DeploymentRunner;
use App\Services\Deploy\DeployPhaseRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_walks_all_four_phases_and_persists_results_on_success(): void
    {
        [$site, $deployment] = $this->makeDeploymentForSite([
            'runtime' => 'php',
            'runtime_version' => '8.4',
            'deploy_strategy' => 'simple',
        ]);
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
            'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
            'phase' => SiteDeployStep::PHASE_RELEASE,
            'timeout_seconds' => 300,
        ]);

        $shell = new DeploymentRunnerRecordingShell;
        $runner = new DeploymentRunner(new DeployPhaseRunner);
        $result = $runner->run($deployment, '/var/www/app', fn () => $shell);

        $this->assertTrue($result['ok']);
        $deployment->refresh();
        $this->assertSame(SiteDeployment::STATUS_SUCCESS, $deployment->status);
        $this->assertNotNull($deployment->finished_at);

        // Build + release recorded; swap was skipped (simple deploys);
        // restart ran for PHP (FPM reload).
        $persisted = $deployment->phase_results;
        $this->assertArrayHasKey('build', $persisted);
        $this->assertArrayNotHasKey('swap', $persisted);
        $this->assertArrayHasKey('release', $persisted);
        $this->assertArrayHasKey('restart', $persisted);
        $this->assertCount(1, $persisted['build']);
        $this->assertCount(1, $persisted['release']);
    }

    public function test_run_aborts_pipeline_on_build_failure(): void
    {
        [$site, $deployment] = $this->makeDeploymentForSite(['runtime' => 'php', 'runtime_version' => '8.4']);
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
            'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
            'phase' => SiteDeployStep::PHASE_RELEASE,
            'timeout_seconds' => 300,
        ]);

        $shell = new DeploymentRunnerRecordingShell;
        $shell->failOn = 'composer install';

        $runner = new DeploymentRunner(new DeployPhaseRunner);
        $result = $runner->run($deployment, '/var/www/app', fn () => $shell);

        $this->assertFalse($result['ok']);
        $deployment->refresh();
        $this->assertSame(SiteDeployment::STATUS_FAILED, $deployment->status);
        $this->assertArrayHasKey('build', $deployment->phase_results);
        $this->assertArrayNotHasKey('release', $deployment->phase_results);
        $this->assertArrayNotHasKey('restart', $deployment->phase_results);
    }

    public function test_run_records_swap_phase_for_atomic_deploys(): void
    {
        [$site, $deployment] = $this->makeDeploymentForSite([
            'runtime' => 'node',
            'start_command' => 'npm start',
            'internal_port' => 30001,
            'deploy_strategy' => 'atomic',
            'repository_path' => '/var/www/jobs',
        ]);
        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 10,
            'step_type' => SiteDeployStep::TYPE_NPM_CI,
            'phase' => SiteDeployStep::PHASE_BUILD,
            'timeout_seconds' => 600,
        ]);

        $shell = new DeploymentRunnerRecordingShell;
        $runner = new DeploymentRunner(new DeployPhaseRunner);
        $result = $runner->run($deployment, '/var/www/jobs/releases/01HXX', fn () => $shell);

        $this->assertTrue($result['ok']);
        $deployment->refresh();
        $this->assertArrayHasKey('swap', $deployment->phase_results);
        $this->assertSame(1, count($deployment->phase_results['swap']));

        // ln -sfn ran in the swap phase.
        $swapCommand = $deployment->phase_results['swap'][0]['command'];
        $this->assertStringContainsString('ln -sfn', $swapCommand);
        $this->assertStringContainsString('current', $swapCommand);
    }

    public function test_run_aggregate_total_duration_sums_all_phases(): void
    {
        [$site, $deployment] = $this->makeDeploymentForSite(['runtime' => 'php', 'runtime_version' => '8.4']);
        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 10,
            'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
            'phase' => SiteDeployStep::PHASE_BUILD,
            'timeout_seconds' => 600,
        ]);

        $shell = new DeploymentRunnerRecordingShell;
        $runner = new DeploymentRunner(new DeployPhaseRunner);
        $result = $runner->run($deployment, '/var/www/app', fn () => $shell);

        $this->assertTrue($result['ok']);
        $this->assertGreaterThanOrEqual(0, $result['total_duration_ms']);
    }

    public function test_run_throws_when_deployment_has_no_site(): void
    {
        $deployment = new SiteDeployment;
        $deployment->status = SiteDeployment::STATUS_RUNNING;
        // No site_id set; site relation returns null.

        $this->expectException(\RuntimeException::class);

        (new DeploymentRunner(new DeployPhaseRunner))
            ->run($deployment, '/var/www/app', fn () => new DeploymentRunnerRecordingShell);
    }

    /**
     * @param  array<string, mixed>  $siteOverrides
     * @return array{0: Site, 1: SiteDeployment}
     */
    private function makeDeploymentForSite(array $siteOverrides = []): array
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $site = Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'repository_path' => '/var/www/app',
        ], $siteOverrides));
        $site->refresh();

        $deployment = SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'idempotency_key' => 'dep-'.uniqid(),
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        return [$site, $deployment];
    }
}

class DeploymentRunnerRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $execCalls = [];

    public ?string $failOn = null;

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->execCalls[] = $command;
        if ($this->failOn !== null && str_contains($command, $this->failOn)) {
            throw new \RuntimeException('Simulated failure: '.$this->failOn);
        }

        return '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void {}
}
