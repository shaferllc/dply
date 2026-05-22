<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDeploymentPhaseResultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_phase_results_persists_under_phase_key(): void
    {
        $deployment = $this->makeDeployment();

        $deployment->recordPhaseResults('build', [
            ['step_id' => '1', 'step_type' => 'composer_install', 'ok' => true, 'output' => 'ran', 'duration_ms' => 1234],
        ]);
        $deployment->recordPhaseResults('release', [
            ['step_id' => '2', 'step_type' => 'artisan_migrate', 'ok' => true, 'output' => 'ok', 'duration_ms' => 5678],
        ]);

        $deployment->refresh();
        $this->assertSame('composer_install', $deployment->phase_results['build'][0]['step_type']);
        $this->assertSame('artisan_migrate', $deployment->phase_results['release'][0]['step_type']);
    }

    public function test_record_phase_results_replaces_prior_phase_data(): void
    {
        $deployment = $this->makeDeployment();
        $deployment->recordPhaseResults('build', [
            ['step_id' => '1', 'ok' => false, 'output' => 'fail', 'duration_ms' => 100],
        ]);
        $deployment->recordPhaseResults('build', [
            ['step_id' => '2', 'ok' => true, 'output' => 'success', 'duration_ms' => 50],
        ]);

        $deployment->refresh();
        $this->assertCount(1, $deployment->phase_results['build']);
        $this->assertTrue($deployment->phase_results['build'][0]['ok']);
    }

    public function test_phases_all_ok_returns_true_when_every_step_passed(): void
    {
        $deployment = $this->makeDeployment();
        $deployment->recordPhaseResults('build', [
            ['step_id' => '1', 'ok' => true, 'duration_ms' => 100],
            ['step_id' => '2', 'ok' => true, 'duration_ms' => 50, 'skipped' => true],
        ]);
        $deployment->recordPhaseResults('release', [
            ['step_id' => '3', 'ok' => true, 'duration_ms' => 200],
        ]);

        $this->assertTrue($deployment->phasesAllOk());
    }

    public function test_phases_all_ok_returns_false_when_any_step_failed(): void
    {
        $deployment = $this->makeDeployment();
        $deployment->recordPhaseResults('build', [
            ['step_id' => '1', 'ok' => true, 'duration_ms' => 100],
        ]);
        $deployment->recordPhaseResults('release', [
            ['step_id' => '2', 'ok' => false, 'output' => 'boom', 'duration_ms' => 80],
        ]);

        $this->assertFalse($deployment->phasesAllOk());
    }

    public function test_phases_all_ok_is_false_when_no_phases_recorded(): void
    {
        $deployment = $this->makeDeployment();

        // Nothing recorded yet — treat as not-ok so callers can
        // distinguish "haven't run" from "all phases passed".
        $this->assertFalse($deployment->phasesAllOk());
    }

    public function test_phase_total_duration_sums_all_steps(): void
    {
        $deployment = $this->makeDeployment();
        $deployment->recordPhaseResults('build', [
            ['step_id' => '1', 'ok' => true, 'duration_ms' => 1000],
            ['step_id' => '2', 'ok' => true, 'duration_ms' => 2500],
        ]);
        $deployment->recordPhaseResults('swap', [
            ['step_id' => 'swap', 'ok' => true, 'duration_ms' => 12],
        ]);
        $deployment->recordPhaseResults('release', [
            ['step_id' => '3', 'ok' => true, 'duration_ms' => 800],
        ]);
        $deployment->recordPhaseResults('restart', [
            ['step_id' => 'restart', 'ok' => true, 'duration_ms' => 5],
        ]);

        $this->assertSame(1000 + 2500 + 12 + 800 + 5, $deployment->phaseTotalDurationMs());
    }

    public function test_phase_total_duration_is_zero_when_unset(): void
    {
        $this->assertSame(0, $this->makeDeployment()->phaseTotalDurationMs());
    }

    public function test_phase_results_round_trips_through_db_as_json(): void
    {
        $deployment = $this->makeDeployment();
        $deployment->recordPhaseResults('build', [
            ['step_id' => '1', 'command' => 'composer install', 'ok' => true, 'output' => 'foo', 'duration_ms' => 100],
        ]);

        $reloaded = SiteDeployment::query()->whereKey($deployment->id)->firstOrFail();
        $this->assertIsArray($reloaded->phase_results);
        $this->assertSame('composer install', $reloaded->phase_results['build'][0]['command']);
    }

    private function makeDeployment(): SiteDeployment
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        // Site::created auto-creates a Project; reuse it for the
        // deployment so the not-null FK is satisfied.
        $site->refresh();

        return SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'idempotency_key' => 'dep-'.uniqid(),
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }
}
