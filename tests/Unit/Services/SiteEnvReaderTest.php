<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteEnvReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteEnvReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_throws_when_runtime_does_not_support_env_push(): void
    {
        $server = Server::factory()->ready()->create([
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
        ]);
        $site = Site::factory()->create(['server_id' => $server->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not expose a server .env file');

        app(SiteEnvReader::class)->read($site);
    }

    public function test_throws_when_server_is_not_ready(): void
    {
        $server = Server::factory()->pending()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server must be ready');

        app(SiteEnvReader::class)->read($site);
    }

    public function test_throws_when_no_ssh_key_present(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => null,
        ]);
        $site = Site::factory()->create(['server_id' => $server->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server must be ready');

        app(SiteEnvReader::class)->read($site);
    }
}
