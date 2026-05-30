<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\User;
use App\Models\UserSshKey;
use App\Services\Servers\ServerSshAccessGraph;
use App\Services\Servers\ServerSshAccessTimeline;
use App\Services\Servers\ServerSshSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use phpseclib3\Crypt\PublicKeyLoader;

uses(RefreshDatabase::class);

function contractorPublicKeyLine(): string
{
    $pem = file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem'));

    return trim(PublicKeyLoader::loadPrivateKey($pem)->getPublicKey()->toString('OpenSSH'));
}

test('ssh session manager grants and revokes contractor key', function (): void {
    Carbon::setTestNow('2026-05-27 12:00:00');

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => 'vm'],
    ]);

    $session = app(ServerSshSessionManager::class)->grant(
        $server,
        $user,
        'Contractor review',
        contractorPublicKeyLine(),
        now()->addHours(8),
        'dply',
    );

    expect($session->isActive())->toBeTrue()
        ->and(ServerAuthorizedKey::query()->where('server_id', $server->id)->count())->toBe(1);

    app(ServerSshSessionManager::class)->revoke($session->fresh());

    expect($session->fresh()->isRevoked())->toBeTrue()
        ->and(ServerAuthorizedKey::query()->where('server_id', $server->id)->count())->toBe(0);
});

test('ssh session manager revokes expired sessions', function (): void {
    Carbon::setTestNow('2026-05-27 12:00:00');

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_PENDING,
        'meta' => ['host_kind' => 'vm'],
    ]);

    $session = app(ServerSshSessionManager::class)->grant(
        $server,
        $user,
        'Expired contractor',
        contractorPublicKeyLine(),
        now()->addHour(),
    );

    Carbon::setTestNow('2026-05-27 14:00:00');

    expect(app(ServerSshSessionManager::class)->revokeExpired())->toBe(1)
        ->and($session->fresh()->isRevoked())->toBeTrue();
});

test('ssh access graph labels session keys', function (): void {
    Carbon::setTestNow('2026-05-27 12:00:00');

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    app(ServerSshSessionManager::class)->grant(
        $server,
        $user,
        'Contractor',
        contractorPublicKeyLine(),
        now()->addDay(),
    );

    $report = app(ServerSshAccessGraph::class)->forServer($server->fresh());

    expect($report['summary']['active_sessions'])->toBe(1)
        ->and(collect($report['rows'])->pluck('source'))->toContain('session');
});

test('access timeline highlights viewer profile keys and builds series', function (): void {
    Carbon::setTestNow('2026-05-27 12:00:00');

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    $profileKey = UserSshKey::query()->create([
        'user_id' => $user->id,
        'name' => 'Laptop',
        'public_key' => contractorPublicKeyLine(),
    ]);

    $profileAuthorized = ServerAuthorizedKey::query()->create([
        'server_id' => $server->id,
        'managed_key_type' => UserSshKey::class,
        'managed_key_id' => $profileKey->id,
        'name' => 'Laptop',
        'public_key' => $profileKey->public_key,
        'target_linux_user' => 'dply',
    ]);
    $profileAuthorized->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

    $otherAuthorized = ServerAuthorizedKey::query()->create([
        'server_id' => $server->id,
        'name' => 'Contractor',
        'public_key' => 'ssh-ed25519 AAA other',
        'target_linux_user' => 'dply',
    ]);
    $otherAuthorized->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

    $timeline = app(ServerSshAccessTimeline::class)->forServer($server->fresh(), $user, '30d');

    expect($timeline['you_active_now'])->toBeTrue()
        ->and(collect($timeline['lanes'])->contains(fn (array $lane) => $lane['is_you']))->toBeTrue()
        ->and(collect($timeline['lanes'])->count())->toBeGreaterThanOrEqual(2)
        ->and(collect($timeline['series'])->last()['total'])->toBeGreaterThanOrEqual(2.0)
        ->and(collect($timeline['series'])->last()['you'])->toBeGreaterThanOrEqual(1.0);
});
