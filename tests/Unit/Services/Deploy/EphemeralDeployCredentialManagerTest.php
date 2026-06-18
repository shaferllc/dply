<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Modules\Deploy\Services\EphemeralDeployCredentialContext;
use App\Modules\Deploy\Services\EphemeralDeployCredentialManager;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

test('shouldUseForSite requires vm host org flag and site opt-in', function () {
    Feature::define('workspace.ephemeral_credentials', fn (): bool => true);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $project = Project::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Deploy Project',
        'slug' => 'deploy-project',
        'kind' => Project::KIND_BYO_SITE,
    ]);
    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'ssh_private_key' => testSshPrivateKey(),
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'project_id' => $project->id,
        'meta' => ['deploy' => ['ephemeral_credentials' => true]],
    ]);

    $manager = app(EphemeralDeployCredentialManager::class);

    expect($manager->shouldUseForSite($site->fresh()))->toBeTrue();

    $site->update(['meta' => ['deploy' => ['ephemeral_credentials' => false]]]);
    expect($manager->shouldUseForSite($site->fresh()))->toBeFalse();
});

test('shouldUseForSite returns false when workspace flag is off', function () {
    Feature::define('workspace.ephemeral_credentials', fn (): bool => false);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $project = Project::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Deploy Project Flag Off',
        'slug' => 'deploy-project-flag-off',
        'kind' => Project::KIND_BYO_SITE,
    ]);
    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'ssh_private_key' => testSshPrivateKey(),
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'project_id' => $project->id,
        'meta' => ['deploy' => ['ephemeral_credentials' => true]],
    ]);

    expect(app(EphemeralDeployCredentialManager::class)->shouldUseForSite($site->fresh()))->toBeFalse();
});

test('provision and revoke manage authorized key lifecycle', function () {
    Feature::define('workspace.ephemeral_credentials', fn (): bool => true);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $project = Project::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Deploy Project',
        'slug' => 'deploy-project-2',
        'kind' => Project::KIND_BYO_SITE,
    ]);
    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'ssh_private_key' => testSshPrivateKey(),
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'project_id' => $project->id,
        'meta' => ['deploy' => ['ephemeral_credentials' => true]],
    ]);
    $deployment = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $project->id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now(),
    ]);

    $syncCalls = 0;
    $this->mock(ServerAuthorizedKeysSynchronizer::class, function ($mock) use (&$syncCalls): void {
        $mock->shouldReceive('sync')->andReturnUsing(function () use (&$syncCalls): string {
            $syncCalls++;

            return 'synced';
        });
    });

    $manager = app(EphemeralDeployCredentialManager::class);
    $credential = $manager->provision($site, $deployment);

    expect($credential->public_key_fingerprint)->not->toBeEmpty();
    expect($credential->server_authorized_key_id)->not->toBeNull();
    expect(ServerAuthorizedKey::query()->count())->toBe(1);
    expect($syncCalls)->toBe(1);

    $manager->activateForDeploy($credential);
    expect(app(EphemeralDeployCredentialContext::class)->hasPrivateKey())->toBeTrue();

    $manager->revoke($credential->fresh());

    expect($credential->fresh()->revoked_at)->not->toBeNull();
    expect(ServerAuthorizedKey::query()->count())->toBe(0);
    expect(app(EphemeralDeployCredentialContext::class)->hasPrivateKey())->toBeFalse();
    expect($syncCalls)->toBe(2);

    $manager->revoke($credential->fresh());
    expect($syncCalls)->toBe(2);
});

function testSshPrivateKey(): string
{
    return "-----BEGIN OPENSSH PRIVATE KEY-----\n".
        "b3BlbnNzaC1lZDI1NTE5AAAAIHRlc3QtcHJpdmF0ZS1rZXk=\n".
        '-----END OPENSSH PRIVATE KEY-----';
}
