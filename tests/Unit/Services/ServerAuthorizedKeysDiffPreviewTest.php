<?php

namespace Tests\Unit\Services\ServerAuthorizedKeysDiffPreviewTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\User;
use App\Services\Servers\ServerAuthorizedKeysDiffPreview;
use App\Services\Servers\ServerAuthorizedKeysRemoteReader;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use App\Services\Servers\SshPublicKeyFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

uses(RefreshDatabase::class);

function validPrivateKey(): string
{
    return file_get_contents(base_path('app/Modules/TaskRunner/Tests/fixtures/private_key.pem'));
}

it('reports added and removed lines per user', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ssh_user' => 'root',
        'ssh_private_key' => validPrivateKey(),
        'meta' => [
            ServerAuthorizedKeysSynchronizer::META_SYNCED_LINUX_USERS_KEY => ['root'],
        ],
    ]);

    // Real ed25519 material — placeholder base64 blobs don't fingerprint via phpseclib.
    $kPanel = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ14kMcp16eYiPmSIyqw+29AAdguesdPBnOXCc6xyf/u panel';
    ServerAuthorizedKey::query()->create([
        'server_id' => $server->id,
        'name' => 'panel',
        'public_key' => $kPanel,
        'target_linux_user' => '',
    ]);

    $kRemoteOld = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFuwxYXsyfYafmWSGxBVhdvEDKCjffV5QIbjEJWZ3Y51 remote-old';
    $kRemoteOldFp = SshPublicKeyFingerprint::shortSha256($kRemoteOld);
    expect($kRemoteOldFp)->not->toBeNull();

    // Diff preview only lists a remote key under "removed" when dply previously
    // managed it (fingerprint in meta). Unmanaged/foreign keys stay under "kept".
    $server->update([
        'meta' => array_merge($server->meta ?? [], [
            ServerAuthorizedKeysSynchronizer::META_SYNCED_LINUX_USERS_KEY => ['root'],
            ServerAuthorizedKeysSynchronizer::META_MANAGED_FINGERPRINTS_KEY => [
                'root' => [$kRemoteOldFp],
            ],
        ]),
    ]);

    $reader = Mockery::mock(ServerAuthorizedKeysRemoteReader::class);
    $reader->shouldReceive('normalizedKeyLines')
        ->once()
        ->with(Mockery::on(fn (Server $s) => $s->is($server)), 'root')
        ->andReturn([$kRemoteOld]);

    $preview = new ServerAuthorizedKeysDiffPreview($reader);
    $diff = $preview->diffPerUser($server->fresh(['authorizedKeys']));

    expect($diff)->toHaveKey('root');

    // The service auto-injects the recovery public key (derived from
    // ssh_private_key) into root's desired list — see
    // ServerAuthorizedKeysDiffPreview::diffPerUser. So
    // the desired/added arrays carry the panel key + recovery key,
    // and the test asserts the panel key's presence rather than
    // exact-equals the whole list.
    expect($diff['root']['desired'])->toContain($kPanel);
    expect($diff['root']['desired'])->toHaveCount(2);
    expect($diff['root']['remote'])->toBe([$kRemoteOld]);
    expect($diff['root']['added'])->toContain($kPanel);
    expect($diff['root']['added'])->toHaveCount(2);
    expect($diff['root']['removed'])->toBe([$kRemoteOld]);
});
