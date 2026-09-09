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

test('compose infers tls for digitalocean managed redis without wiping other keys', function () {
    $server = Server::factory()->ready()->create(['ssh_private_key' => 'fake']);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'env_file_content' => "APP_KEY=base64:test\nREDIS_HOST=cache.db.ondigitalocean.com\nREDIS_PORT=25061\nREDIS_PASSWORD=secret\n",
    ]);

    $vars = app(SiteEnvPusher::class)->composeVariables($site);

    expect($vars['REDIS_SCHEME'])->toBe('tls')
        ->and($vars['APP_KEY'])->toBe('base64:test')
        ->and($vars['REDIS_HOST'])->toBe('cache.db.ondigitalocean.com')
        ->and($vars)->not->toHaveKey('REDIS_URL');
});

test('compose leaves local redis on 127.0.0.1 without a scheme', function () {
    $server = Server::factory()->ready()->create(['ssh_private_key' => 'fake']);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'env_file_content' => "REDIS_HOST=127.0.0.1\nREDIS_PORT=6379\n",
    ]);

    $vars = app(SiteEnvPusher::class)->composeVariables($site);

    expect($vars)->not->toHaveKey('REDIS_SCHEME')
        ->and($vars['REDIS_HOST'])->toBe('127.0.0.1');
});

test('throws when server is not ready', function () {
    $server = Server::factory()->pending()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Server must be ready');

    app(SiteEnvPusher::class)->push($site);
});
