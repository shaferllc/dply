<?php

declare(strict_types=1);

namespace Tests\Unit\ServerTagsTest;

use App\Models\Server;
use App\Support\Servers\ServerTags;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('for server returns trimmed unique tags from meta', function (): void {
    $server = Server::factory()->make([
        'meta' => ['tags' => [' production ', 'web', '']],
    ]);

    expect(ServerTags::forServer($server))->toBe(['production', 'web']);
});

test('collect from servers returns sorted unique tags', function (): void {
    $a = Server::factory()->make(['meta' => ['tags' => ['beta', 'web']]]);
    $b = Server::factory()->make(['meta' => ['tags' => ['alpha', 'web']]]);

    expect(ServerTags::collectFromServers(collect([$a, $b])))->toBe(['alpha', 'beta', 'web']);
});
