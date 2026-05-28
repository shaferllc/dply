<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\User;
use App\Services\Servers\ServerSshAccessGraph;
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
