<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteBindingManagerDatabaseAttachTest;

use App\Models\Organization;
use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Modules\Deploy\Services\SiteBindingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: Site, 2: Server}
 */
function dbAttachFixture(): array
{
    $org = Organization::factory()->create();
    $appServer = Server::factory()->create([
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
        'private_ip_address' => '10.10.0.10',
    ]);
    $site = Site::factory()->create([
        'server_id' => $appServer->id,
        'organization_id' => $org->id,
        'user_id' => $appServer->user_id,
    ]);

    return [$org, $site, $appServer];
}

function databaseOn(Server $server, string $name, bool $remoteAccess = true): ServerDatabase
{
    return ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => $name,
        'engine' => 'mysql',
        'username' => 'dply_app',
        'password' => 'secret',
        'host' => (string) $server->ip_address,
        'remote_access' => $remoteAccess,
    ]);
}

/** @return array<string, array<string, mixed>> */
function targetsById(Site $site): array
{
    $targets = app(SiteBindingManager::class)->attachableTargets($site, 'database');

    return collect($targets)->keyBy(fn (array $t): string => (string) $t['id'])->all();
}

// The picker used to filter on reachableServerIds(), so a database on an org
// server with no PrivateNetwork row was invisible — and there was no way to
// tell that from the UI.
test('databases on unlinked org servers are offered, labelled as public', function () {
    [$org, $site, $appServer] = dbAttachFixture();

    $otherServer = Server::factory()->create([
        'organization_id' => $org->id,
        'name' => 'db-box',
        'ip_address' => '203.0.113.99',
        'private_ip_address' => '10.20.0.5',
    ]);

    $local = databaseOn($appServer, 'local_db');
    $remote = databaseOn($otherServer, 'remote_db');

    $targets = targetsById($site);

    expect($targets)->toHaveKeys([(string) $local->id, (string) $remote->id]);
    expect($targets[(string) $local->id]['group'])->toBe('local');
    expect($targets[(string) $remote->id]['group'])->toBe('org');
    expect($targets[(string) $remote->id]['label'])
        ->toContain('db-box')
        ->toContain('over public IP');
});

test('a shared private network still groups as a peer and drops the public note', function () {
    [$org, $site, $appServer] = dbAttachFixture();

    $network = PrivateNetwork::query()->create([
        'organization_id' => $org->id,
        'name' => 'vpc',
        'provider' => PrivateNetwork::PROVIDER_DO,
        'ip_range' => '10.10.0.0/16',
    ]);
    $appServer->forceFill(['private_network_id' => $network->id])->save();

    $peerServer = Server::factory()->create([
        'organization_id' => $org->id,
        'name' => 'peer-box',
        'ip_address' => '203.0.113.50',
        'private_ip_address' => '10.10.0.20',
        'private_network_id' => $network->id,
    ]);

    $peerDb = databaseOn($peerServer, 'peer_db');

    $targets = targetsById($site->fresh());

    expect($targets[(string) $peerDb->id]['group'])->toBe('peer');
    expect($targets[(string) $peerDb->id]['label'])->not->toContain('over public IP');
});

test('remote access still shows on the label so the firewall gap is visible', function () {
    [$org, $site] = dbAttachFixture();

    $otherServer = Server::factory()->create([
        'organization_id' => $org->id,
        'name' => 'closed-box',
        'ip_address' => '203.0.113.77',
    ]);
    $closed = databaseOn($otherServer, 'closed_db', remoteAccess: false);

    expect(targetsById($site)[(string) $closed->id]['label'])->toContain('remote access off');
});

// Widening the picker without widening the save path would offer options that
// saving then rejects.
test('another organization stays out of the picker and off the save path', function () {
    [, $site] = dbAttachFixture();

    $foreignServer = Server::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'ip_address' => '198.51.100.5',
    ]);
    $foreignDb = databaseOn($foreignServer, 'foreign_db');

    expect(targetsById($site))->not->toHaveKey((string) $foreignDb->id);

    expect(fn () => app(SiteBindingManager::class)->attachExisting($site, 'database', [
        'target_id' => (string) $foreignDb->id,
    ]))->toThrow(\InvalidArgumentException::class);
});
