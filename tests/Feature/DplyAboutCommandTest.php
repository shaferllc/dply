<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DplyAboutCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_payload_includes_versions_and_counts(): void
    {
        Artisan::call('dply:about', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertArrayHasKey('dply', $decoded);
        $this->assertArrayHasKey('version', $decoded['dply']);
        $this->assertArrayHasKey('laravel', $decoded['dply']);
        $this->assertArrayHasKey('php', $decoded['dply']);
        $this->assertSame(PHP_VERSION, $decoded['dply']['php']);
        $this->assertArrayHasKey('fleet', $decoded);
        $this->assertSame(0, $decoded['fleet']['servers']);
        $this->assertSame(0, $decoded['fleet']['sites']);
    }

    public function test_command_count_includes_dply_namespaced(): void
    {
        Artisan::call('dply:about', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        // We just shipped this command itself — and many others. The exact
        // count is fluid as we add commands, so just sanity-check there are
        // a meaningful number.
        $this->assertGreaterThan(20, $decoded['commands']['dply_total']);
    }

    public function test_fleet_counts_reflect_seeded_data(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id]);
        Site::factory()->create(['server_id' => $server->id]);

        Artisan::call('dply:about', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $decoded['fleet']['servers']);
        $this->assertSame(2, $decoded['fleet']['sites']);
    }

    public function test_fleet_counts_include_edge_breakdown(): void
    {
        $server = Server::factory()->create([
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        // 1 image-mode + 1 source-mode + 1 source-mode preview
        Site::factory()->create([
            'server_id' => $server->id,
            'type' => \App\Enums\SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
        ]);
        $parent = Site::factory()->create([
            'server_id' => $server->id,
            'type' => \App\Enums\SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'type' => \App\Enums\SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_PROVISIONING,
            'meta' => [
                'container' => [
                    'source' => ['repo' => 'acme/api', 'branch' => 'feature/x'],
                    'preview_parent_site_id' => $parent->id,
                    'preview_branch' => 'feature/x',
                ],
            ],
        ]);

        Artisan::call('dply:about', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(3, $decoded['fleet']['edge_sites']);
        $this->assertSame(2, $decoded['fleet']['edge_source_mode_sites']);
        $this->assertSame(1, $decoded['fleet']['edge_preview_sites']);
    }

    public function test_human_output_renders_section_headings(): void
    {
        $exit = Artisan::call('dply:about');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('dply', $output);
        $this->assertStringContainsString('Laravel', $output);
        $this->assertStringContainsString('Commands', $output);
        $this->assertStringContainsString('Fleet', $output);
    }
}
