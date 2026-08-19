<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\Site;
use App\Support\Servers\ProviderResourceTags;

function taggedServer(string $id = '01jabcdefghijklmnopqrstuvw'): Server
{
    $server = new Server;
    $server->forceFill(['id' => $id]);

    return $server;
}

test('identity tag is dply- plus the server id', function (): void {
    expect(ProviderResourceTags::forServer(taggedServer()))
        ->toBe('dply-01jabcdefghijklmnopqrstuvw');
});

test('flat tags carry the marker and the identity tag', function (): void {
    expect(ProviderResourceTags::tags(taggedServer()))
        ->toBe(['dply', 'dply-01jabcdefghijklmnopqrstuvw']);
});

test('identity tag stays inside the tightest provider length limit', function (): void {
    // Linode caps tags at 50 chars; Hetzner label values at 63.
    expect(strlen(ProviderResourceTags::forServer(taggedServer())))->toBeLessThanOrEqual(50);
});

test('merge keeps user tags and drops blanks and duplicates', function (): void {
    $merged = ProviderResourceTags::mergeTags(taggedServer(), ['prod', '', '  ', 'prod', 'dply']);

    expect($merged)->toBe(['dply', 'dply-01jabcdefghijklmnopqrstuvw', 'prod']);
});

test('labels key the marker and the server id', function (): void {
    expect(ProviderResourceTags::labels(taggedServer()))->toBe([
        'managed-by' => 'dply',
        'dply-server-id' => '01jabcdefghijklmnopqrstuvw',
    ]);
});

test('managed database tags include the kind plus server and site', function (): void {
    $server = taggedServer();
    $site = new Site;
    $site->forceFill(['id' => '01jsiteidabcdefghijklmnop']);
    $site->setRelation('server', $server);

    expect(ProviderResourceTags::forSite($site))->toBe('dply-site-01jsiteidabcdefghijklmnop')
        ->and(ProviderResourceTags::forManagedDatabase($server, $site))->toBe([
            'dply',
            'dply-database',
            'dply-01jabcdefghijklmnopqrstuvw',
            'dply-site-01jsiteidabcdefghijklmnop',
        ])
        ->and(ProviderResourceTags::forManagedDatabase())->toBe(['dply', 'dply-database']);
});

test('ownership is recognised from either tag shape', function (): void {
    $server = taggedServer();

    expect(ProviderResourceTags::belongsToServer($server, ProviderResourceTags::tags($server)))->toBeTrue()
        ->and(ProviderResourceTags::belongsToServer($server, ProviderResourceTags::labels($server)))->toBeTrue()
        ->and(ProviderResourceTags::belongsToServer($server, ['dply', 'dply-someoneelse']))->toBeFalse()
        ->and(ProviderResourceTags::belongsToServer($server, []))->toBeFalse();
});
