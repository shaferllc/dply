<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FleetSummaryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_aggregates_runtime_counts_across_sites(): void
    {
        $server = Server::factory()->create(['status' => Server::STATUS_READY]);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'node']);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'python']);
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'is_default' => true,
        ]);

        $exit = Artisan::call('dply:fleet:summary', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertSame(1, $decoded['totals']['servers']);
        $this->assertSame(4, $decoded['totals']['sites']);
        $this->assertSame(2, $decoded['site_runtimes']['php']);
        $this->assertSame(1, $decoded['site_runtimes']['node']);
        $this->assertSame(1, $decoded['site_runtimes']['python']);
        $this->assertSame(1, $decoded['engine_usage']['postgres']);
    }

    public function test_command_renders_human_table(): void
    {
        $server = Server::factory()->create(['status' => Server::STATUS_READY]);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);

        $exit = Artisan::call('dply:fleet:summary');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Fleet summary', $output);
        $this->assertStringContainsString('Servers by status', $output);
        $this->assertStringContainsString('Sites by runtime', $output);
        $this->assertStringContainsString('php', $output);
    }

    public function test_command_handles_empty_fleet(): void
    {
        $exit = Artisan::call('dply:fleet:summary', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertSame(0, $decoded['totals']['servers']);
        $this->assertSame(0, $decoded['totals']['sites']);
        $this->assertSame([], $decoded['site_runtimes']);
        $this->assertSame([], $decoded['engine_usage']);
    }

    public function test_command_groups_unset_runtime_under_unset_key(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id, 'runtime' => null]);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'go']);

        $exit = Artisan::call('dply:fleet:summary', ['--json' => true]);
        $output = Artisan::output();

        $decoded = json_decode($output, true);
        $this->assertSame(1, $decoded['site_runtimes']['unset']);
        $this->assertSame(1, $decoded['site_runtimes']['go']);
    }

    public function test_fly_io_section_reports_eligible_sites_when_not_connected(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'node']);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'static']);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']); // not eligible

        Artisan::call('dply:fleet:summary', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertFalse($decoded['fly_io']['connected']);
        $this->assertSame(2, $decoded['fly_io']['edge_eligible_sites']);
    }

    public function test_fly_io_section_marks_connected_when_credential_exists(): void
    {
        \App\Models\ProviderCredential::factory()->create([
            'provider' => 'fly_io',
            'name' => 'Fly token',
            'credentials' => ['api_token' => 't'],
        ]);

        Artisan::call('dply:fleet:summary', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['fly_io']['connected']);
    }

    public function test_edge_fleet_section_aggregates_by_backend_and_status(): void
    {
        $user = \App\Models\User::factory()->create();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'type' => \App\Enums\SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => 'digitalocean_app_platform',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'type' => \App\Enums\SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'public.ecr.aws/x/y:1',
            'container_port' => 8080,
            'container_backend' => 'aws_app_runner',
            'status' => Site::STATUS_CONTAINER_FAILED,
        ]);

        Artisan::call('dply:fleet:summary', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(2, $decoded['edge_fleet']['total']);
        $this->assertSame(1, $decoded['edge_fleet']['by_backend']['digitalocean_app_platform']);
        $this->assertSame(1, $decoded['edge_fleet']['by_backend']['aws_app_runner']);
        $this->assertSame(1, $decoded['edge_fleet']['by_status'][Site::STATUS_CONTAINER_ACTIVE]);
        $this->assertSame(1, $decoded['edge_fleet']['by_status'][Site::STATUS_CONTAINER_FAILED]);
    }

    public function test_edge_fleet_section_empty_when_no_container_sites(): void
    {
        Artisan::call('dply:fleet:summary', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $decoded['edge_fleet']['total']);
        $this->assertSame([], $decoded['edge_fleet']['by_backend']);
    }

    public function test_edge_fleet_human_output_renders_section(): void
    {
        $user = \App\Models\User::factory()->create();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'type' => \App\Enums\SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => 'digitalocean_app_platform',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
        ]);

        Artisan::call('dply:fleet:summary');
        $output = Artisan::output();

        $this->assertStringContainsString('Dply edge', $output);
        $this->assertStringContainsString('1 edge container site', $output);
        $this->assertStringContainsString('digitalocean_app_platform', $output);
    }
}
