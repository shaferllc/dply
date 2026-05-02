<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SiteDeployHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_lists_recent_deployments_with_phase_summary(): void
    {
        $site = $this->makeSiteWithDeployments();

        $exit = Artisan::call('dply:site:deploy-history', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Recent deployments', $output);
        $this->assertStringContainsString('success', $output);
        $this->assertStringContainsString('build(1)', $output);
        $this->assertStringContainsString('release(1)', $output);
    }

    public function test_command_emits_json_with_phase_breakdown(): void
    {
        $site = $this->makeSiteWithDeployments();

        $exit = Artisan::call('dply:site:deploy-history', [
            'site' => $site->slug,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame($site->id, $decoded['site_id']);
        $this->assertGreaterThanOrEqual(1, $decoded['count']);
        $this->assertArrayHasKey('build', $decoded['deployments'][0]['phases']);
        $this->assertTrue($decoded['deployments'][0]['phases']['build']['ok']);
    }

    public function test_command_returns_zero_with_message_when_no_deployments(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'fresh-site',
        ]);

        $exit = Artisan::call('dply:site:deploy-history', ['site' => 'fresh-site']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No deployments recorded', $output);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:deploy-history', ['site' => 'no-such']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    public function test_limit_option_caps_returned_rows(): void
    {
        $site = $this->makeSiteWithDeployments(count: 5);

        $exit = Artisan::call('dply:site:deploy-history', [
            'site' => $site->slug,
            '--limit' => 2,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertSame(2, $decoded['count']);
    }

    private function makeSiteWithDeployments(int $count = 1): Site
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'jobs',
        ]);
        $site->refresh();

        for ($i = 0; $i < $count; $i++) {
            $deployment = SiteDeployment::query()->create([
                'site_id' => $site->id,
                'project_id' => $site->project_id,
                'idempotency_key' => 'dep-'.$i.'-'.uniqid(),
                'trigger' => 'manual',
                'status' => SiteDeployment::STATUS_SUCCESS,
                'started_at' => now()->subMinutes($i),
                'finished_at' => now()->subSeconds($i),
            ]);
            $deployment->recordPhaseResults('build', [
                ['step_id' => 'b'.$i, 'command' => 'composer install', 'ok' => true, 'output' => '', 'duration_ms' => 4000],
            ]);
            $deployment->recordPhaseResults('release', [
                ['step_id' => 'r'.$i, 'command' => 'php artisan migrate --force', 'ok' => true, 'output' => '', 'duration_ms' => 800],
            ]);
        }

        return $site;
    }
}
