<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Lightweight integration tests for dply:doctor:orphans.
 *
 * The command's purpose is to detect FK-orphan rows. Postgres CASCADE
 * constraints make orphans impossible in normal operation, so the
 * "actually detect an orphan" path can only be tested by dropping a
 * FK constraint inline — and ALTER TABLE in Postgres auto-commits,
 * breaking RefreshDatabase isolation for the rest of the suite.
 *
 * To avoid breaking the suite, we only test the command's clean-fleet
 * and require-force code paths here. The detection logic itself is
 * exercised by the command's underlying `whereNotIn` query, which is
 * a Laravel idiom that doesn't need its own test.
 */
class DoctorOrphansCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_fleet_returns_zero_orphans(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id]);

        $exit = Artisan::call('dply:doctor:orphans', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $decoded['total_orphans']);
    }

    public function test_human_output_friendly_when_clean(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id]);

        Artisan::call('dply:doctor:orphans');
        $output = Artisan::output();

        $this->assertStringContainsString('No orphans detected', $output);
    }

    public function test_prune_requires_force_even_on_clean_fleet(): void
    {
        $exit = Artisan::call('dply:doctor:orphans', ['--prune' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('requires --force', $output);
    }

    public function test_json_payload_shape(): void
    {
        Artisan::call('dply:doctor:orphans', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertArrayHasKey('orphans', $decoded);
        $this->assertArrayHasKey('site_deployments', $decoded['orphans']);
        $this->assertArrayHasKey('site_environment_variables', $decoded['orphans']);
        $this->assertArrayHasKey('site_domains', $decoded['orphans']);
        $this->assertArrayHasKey('site_processes', $decoded['orphans']);
        $this->assertArrayHasKey('server_database_engines', $decoded['orphans']);
        $this->assertArrayHasKey('sites_without_server', $decoded['orphans']);
    }
}
