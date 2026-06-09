<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RunSiteDeploymentJob;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeploymentEphemeralCredential;
use App\Models\User;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use App\Services\Sites\SiteGitDeployer;
use App\Services\SshConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use RuntimeException;
use Tests\Support\FakeRemoteShell;
use Tests\Support\FakeSshConnectionFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::getFacadeRoot()->except([RunSiteDeploymentJob::class]);
});

test('vm deploy provisions and revokes ephemeral ssh credential when enabled', function () {
    Feature::define('workspace.ephemeral_credentials', fn (): bool => true);

    $syncCalls = 0;
    $this->mock(ServerAuthorizedKeysSynchronizer::class, function ($mock) use (&$syncCalls): void {
        $mock->shouldReceive('sync')->andReturnUsing(function () use (&$syncCalls): string {
            $syncCalls++;

            return 'synced';
        });
    });

    app()->instance(
        SshConnectionFactory::class,
        new FakeSshConnectionFactory(new FakeRemoteShell),
    );

    $user = User::factory()->create();
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(14)]);
    $project = Project::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Ephemeral Deploy Project',
        'slug' => 'ephemeral-deploy-project',
        'kind' => Project::KIND_BYO_SITE,
    ]);
    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n".
            "b3BlbnNzaC1lZDI1NTE5AAAAIHRlc3QtcHJpdmF0ZS1rZXk=\n".
            '-----END OPENSSH PRIVATE KEY-----',
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'project_id' => $project->id,
        'user_id' => $user->id,
        'git_repository_url' => 'git@github.com:acme/app.git',
        'git_branch' => 'main',
        'meta' => ['deploy' => ['ephemeral_credentials' => true]],
    ]);

    RunSiteDeploymentJob::dispatchSync($site->fresh(), SiteDeployment::TRIGGER_MANUAL);

    $deployment = SiteDeployment::query()->where('site_id', $site->id)->latest()->firstOrFail();
    expect($deployment->status)->toBe(SiteDeployment::STATUS_SUCCESS);

    $credential = SiteDeploymentEphemeralCredential::query()
        ->where('site_deployment_id', $deployment->id)
        ->first();

    expect($credential)->not->toBeNull();
    expect($credential->revoked_at)->not->toBeNull();
    expect($credential->public_key_fingerprint)->not->toBeEmpty();
    expect(ServerAuthorizedKey::query()->count())->toBe(0);
    expect($syncCalls)->toBe(2);

    expect(AuditLog::query()->where('action', 'site.deploy.ephemeral_credential_provisioned')->exists())->toBeTrue();
    expect(AuditLog::query()->where('action', 'site.deploy.ephemeral_credential_revoked')->exists())->toBeTrue();
});

test('vm deploy skips ephemeral credential when site opt-in is off', function () {
    Feature::define('workspace.ephemeral_credentials', fn (): bool => true);

    $this->mock(ServerAuthorizedKeysSynchronizer::class, function ($mock): void {
        $mock->shouldNotReceive('sync');
    });

    app()->instance(
        SshConnectionFactory::class,
        new FakeSshConnectionFactory(new FakeRemoteShell),
    );

    $user = User::factory()->create();
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(14)]);
    $project = Project::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Standard Deploy Project',
        'slug' => 'standard-deploy-project',
        'kind' => Project::KIND_BYO_SITE,
    ]);
    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n".
            "b3BlbnNzaC1lZDI1NTE5AAAAIHRlc3QtcHJpdmF0ZS1rZXk=\n".
            '-----END OPENSSH PRIVATE KEY-----',
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'project_id' => $project->id,
        'user_id' => $user->id,
        'git_repository_url' => 'git@github.com:acme/app.git',
        'git_branch' => 'main',
        'meta' => ['deploy' => ['ephemeral_credentials' => false]],
    ]);

    RunSiteDeploymentJob::dispatchSync($site->fresh(), SiteDeployment::TRIGGER_MANUAL);

    expect(SiteDeploymentEphemeralCredential::query()->count())->toBe(0);
});

test('vm deploy revokes ephemeral credential when deploy fails', function () {
    Feature::define('workspace.ephemeral_credentials', fn (): bool => true);

    $syncCalls = 0;
    $this->mock(ServerAuthorizedKeysSynchronizer::class, function ($mock) use (&$syncCalls): void {
        $mock->shouldReceive('sync')->andReturnUsing(function () use (&$syncCalls): string {
            $syncCalls++;

            return 'synced';
        });
    });

    $this->mock(SiteGitDeployer::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('Simulated deploy failure'));
    });

    $user = User::factory()->create();
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(14)]);
    $project = Project::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'Failed Deploy Project',
        'slug' => 'failed-deploy-project',
        'kind' => Project::KIND_BYO_SITE,
    ]);
    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n".
            "b3BlbnNzaC1lZDI1NTE5AAAAIHRlc3QtcHJpdmF0ZS1rZXk=\n".
            '-----END OPENSSH PRIVATE KEY-----',
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'project_id' => $project->id,
        'user_id' => $user->id,
        'git_repository_url' => 'git@github.com:acme/app.git',
        'meta' => ['deploy' => ['ephemeral_credentials' => true]],
    ]);

    try {
        RunSiteDeploymentJob::dispatchSync($site->fresh(), SiteDeployment::TRIGGER_MANUAL);
    } catch (RuntimeException) {
        // expected
    }

    $credential = SiteDeploymentEphemeralCredential::query()->first();
    expect($credential)->not->toBeNull();
    expect($credential->revoked_at)->not->toBeNull();
    expect(ServerAuthorizedKey::query()->count())->toBe(0);
    expect($syncCalls)->toBe(2);

    $deployment = SiteDeployment::query()->where('site_id', $site->id)->latest()->firstOrFail();
    expect($deployment->status)->toBe(SiteDeployment::STATUS_FAILED);
});
