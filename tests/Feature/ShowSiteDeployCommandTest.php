<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ShowSiteDeployCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_phase_tree_with_step_status(): void
    {
        // phase_results[$phase] is a flat list of steps (matches what
        // DeploymentRunner produces when it calls recordPhaseResults).
        $deployment = $this->seedDeployment(SiteDeployment::STATUS_SUCCESS, [
            'build' => [
                ['step_type' => 'install', 'command' => 'npm ci', 'ok' => true, 'duration_ms' => 1234],
                ['step_type' => 'build', 'command' => 'npm run build', 'ok' => true, 'duration_ms' => 5678],
            ],
        ]);

        $exit = Artisan::call('dply:site:show-deploy', ['id' => $deployment->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('build', $output);
        $this->assertStringContainsString('install', $output);
        $this->assertStringContainsString('npm run build', $output);
        $this->assertStringContainsString('5678ms', $output);
    }

    public function test_phase_filter_limits_render(): void
    {
        $deployment = $this->seedDeployment(SiteDeployment::STATUS_SUCCESS, [
            'build' => [['step_type' => 'b1', 'command' => 'BUILD_CMD', 'ok' => true]],
            'release' => [['step_type' => 'r1', 'command' => 'RELEASE_CMD', 'ok' => true]],
        ]);

        Artisan::call('dply:site:show-deploy', [
            'id' => $deployment->id,
            '--phase' => 'release',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(['release'], array_keys($decoded['phase_results']));
    }

    public function test_output_flag_includes_captured_text(): void
    {
        $deployment = $this->seedDeployment(SiteDeployment::STATUS_FAILED, [
            'build' => [
                ['step_type' => 'install', 'command' => 'npm ci', 'ok' => false, 'output' => 'error: missing lockfile'],
            ],
        ]);

        $exit = Artisan::call('dply:site:show-deploy', [
            'id' => $deployment->id,
            '--output' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('error: missing lockfile', $output);
    }

    public function test_output_flag_omitted_does_not_include_step_output(): void
    {
        $deployment = $this->seedDeployment(SiteDeployment::STATUS_SUCCESS, [
            'build' => [
                ['step_type' => 'install', 'command' => 'npm ci', 'ok' => true, 'output' => 'INTERNAL_DEBUG_TRACE'],
            ],
        ]);

        Artisan::call('dply:site:show-deploy', ['id' => $deployment->id]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('INTERNAL_DEBUG_TRACE', $output);
    }

    public function test_failed_deployment_exits_non_zero(): void
    {
        $deployment = $this->seedDeployment(SiteDeployment::STATUS_FAILED, [
            'build' => [],
        ]);

        $exit = Artisan::call('dply:site:show-deploy', ['id' => $deployment->id]);

        $this->assertSame(1, $exit);
    }

    public function test_no_phase_results_renders_friendly_message(): void
    {
        $deployment = $this->seedDeployment(SiteDeployment::STATUS_SUCCESS, []);

        Artisan::call('dply:site:show-deploy', ['id' => $deployment->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('No phase results', $output);
    }

    public function test_command_fails_when_deployment_not_found(): void
    {
        $exit = Artisan::call('dply:site:show-deploy', ['id' => 'missing']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Deployment not found', $output);
    }

    /**
     * @param  array<string, mixed>  $phaseResults
     */
    private function seedDeployment(string $status, array $phaseResults): SiteDeployment
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);

        return SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'status' => $status,
            'trigger' => 'manual',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'phase_results' => $phaseResults,
        ]);
    }
}
