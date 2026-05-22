<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteEnvPusherTest;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteEnvPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('throws when runtime does not support env push', function () {
    $server = Server::factory()->ready()->create([
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
    ]);
    $site = Site::factory()->create(['server_id' => $server->id]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('does not support writing a .env file');

    app(SiteEnvPusher::class)->push($site);
});
test('throws with per line errors on malformed cache', function () {
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
});
test('throws when server is not ready', function () {
    $server = Server::factory()->pending()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Server must be ready');

    app(SiteEnvPusher::class)->push($site);
});
