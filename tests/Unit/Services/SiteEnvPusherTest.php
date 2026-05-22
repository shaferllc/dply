<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteEnvPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteEnvPusherTest extends TestCase
{
    use RefreshDatabase;

    public function test_throws_when_runtime_does_not_support_env_push(): void
    {
        $server = Server::factory()->ready()->create([
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
        ]);
        $site = Site::factory()->create(['server_id' => $server->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not support writing a .env file');

        app(SiteEnvPusher::class)->push($site);
    }

    public function test_throws_with_per_line_errors_on_malformed_cache(): void
    {
        $server = Server::factory()->ready()->create(['ssh_private_key' => 'fake']);
        // Malformed cache — a line without '=' should be flagged by the parser
        // and the pusher should refuse to ship it before opening any SSH session.
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'env_file_content' => "GOOD_KEY=ok\nBROKEN_LINE\n",
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('parse errors');

        app(SiteEnvPusher::class)->push($site);
    }

    public function test_throws_when_server_is_not_ready(): void
    {
        $server = Server::factory()->pending()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server must be ready');

        app(SiteEnvPusher::class)->push($site);
    }
}
