<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteEnvReaderTest;
use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteEnvReader;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('throws when runtime does not support env push', function () {
    $server = Server::factory()->ready()->create([
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
    ]);
    $site = Site::factory()->create(['server_id' => $server->id]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('does not expose a server .env file');

    app(SiteEnvReader::class)->read($site);
});
test('throws when server is not ready', function () {
    $server = Server::factory()->pending()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Server must be ready');

    app(SiteEnvReader::class)->read($site);
});
test('throws when no ssh key present', function () {
    $server = Server::factory()->ready()->create([
        'ssh_private_key' => null,
    ]);
    $site = Site::factory()->create(['server_id' => $server->id]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Server must be ready');

    app(SiteEnvReader::class)->read($site);
});
