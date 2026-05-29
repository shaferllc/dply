<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerDeployGitIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function gitIdentityServer(?Organization $org = null, string $serverName = 'Prod Box'): Server
{
    $user = User::factory()->create();
    $org ??= Organization::factory()->create(['name' => 'Acme Corp']);
    $org->users()->attach($user->id, ['role' => 'owner']);

    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => $serverName,
        'ssh_user' => 'dply',
    ]);
}

test('deploy git identity defaults use organization name and server id email', function (): void {
    $server = gitIdentityServer();

    $defaults = app(ServerDeployGitIdentity::class)->defaults($server);

    expect($defaults['name'])->toBe('Acme Corp via Dply')
        ->and($defaults['email'])->toBe('deploy+'.$server->id.'@dply.host');
});

test('deploy git identity defaults fall back to server name when organization name is empty', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['name' => '']);
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Legacy Import',
        'ssh_user' => 'dply',
    ]);

    $defaults = app(ServerDeployGitIdentity::class)->defaults($server);

    expect($defaults['name'])->toBe('Legacy Import via Dply');
});

test('deploy git identity build set script escapes values', function (): void {
    $script = app(ServerDeployGitIdentity::class)->buildSetScript(
        'dply',
        'Acme "Ops"',
        'deploy+test@dply.host',
    );

    expect($script)
        ->toContain("sudo -u 'dply' -H git config --global user.name 'Acme \"Ops\"'")
        ->toContain("sudo -u 'dply' -H git config --global user.email 'deploy+test@dply.host'");
});

test('deploy git identity bootstrap lines skip when both values already set', function (): void {
    $server = gitIdentityServer();
    $lines = app(ServerDeployGitIdentity::class)->bootstrapLinesForServer($server);

    expect($lines)->not->toBeEmpty()
        ->and(implode("\n", $lines))->toContain('already set; skipping');
});

test('deploy git identity deploy user resolves from server ssh user', function (): void {
    $server = gitIdentityServer();
    $server->update(['ssh_user' => 'deploy']);

    expect(app(ServerDeployGitIdentity::class)->deployUser($server->fresh()))->toBe('deploy');
});
