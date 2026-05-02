<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RenameSiteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_updates_name_and_slug(): void
    {
        $site = $this->makeSite(['name' => 'Jobs', 'slug' => 'jobs']);

        $exit = Artisan::call('dply:site:rename', [
            'site' => $site->id,
            '--name' => 'Careers',
            '--slug' => 'careers',
        ]);

        $this->assertSame(0, $exit);
        $site->refresh();
        $this->assertSame('Careers', $site->name);
        $this->assertSame('careers', $site->slug);
    }

    public function test_command_normalizes_slug(): void
    {
        $site = $this->makeSite();

        Artisan::call('dply:site:rename', [
            'site' => $site->id,
            '--slug' => 'New Name With Spaces',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('new-name-with-spaces', $decoded['changes']['slug']['to']);
    }

    public function test_command_rejects_collision_on_same_server(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id, 'slug' => 'taken']);
        $site = Site::factory()->create(['server_id' => $server->id, 'slug' => 'jobs']);

        $exit = Artisan::call('dply:site:rename', [
            'site' => $site->id,
            '--slug' => 'taken',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('already in use', $output);
        $this->assertSame('jobs', $site->fresh()->slug);
    }

    public function test_command_allows_same_slug_on_different_server(): void
    {
        $server1 = Server::factory()->create();
        $server2 = Server::factory()->create();
        Site::factory()->create(['server_id' => $server1->id, 'slug' => 'taken']);
        $site = Site::factory()->create(['server_id' => $server2->id, 'slug' => 'jobs']);

        $exit = Artisan::call('dply:site:rename', [
            'site' => $site->id,
            '--slug' => 'taken',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('taken', $site->fresh()->slug);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $site = $this->makeSite(['name' => 'Old']);

        Artisan::call('dply:site:rename', [
            'site' => $site->id,
            '--name' => 'New',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['dry_run']);
        $this->assertSame('Old', $site->fresh()->name);
    }

    public function test_command_fails_when_neither_option_given(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:rename', ['site' => $site->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pass --name or --slug', $output);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:rename', [
            'site' => 'nope',
            '--name' => 'foo',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeSite(array $attrs = []): Site
    {
        $server = Server::factory()->create();

        return Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'slug' => 'jobs',
        ], $attrs));
    }
}
