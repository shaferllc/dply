<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DeploymentSecretInventoryOrgSecretsTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Deploy\Services\DeploymentSecretInventory;
use App\Services\Sites\SiteDotEnvComposer;
use App\Support\Sites\OrganizationSecretManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('linked secrets sit between workspace vars and the site env file', function () {
    $workspace = Workspace::factory()->create();
    $workspace->variables()->create([
        'env_key' => 'SHARED_KEY',
        'env_value' => 'from-workspace',
        'is_secret' => false,
    ]);
    $workspace->variables()->create([
        'env_key' => 'WORKSPACE_ONLY',
        'env_value' => 'ws',
        'is_secret' => false,
    ]);

    $user = User::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $workspace->organization_id,
        'user_id' => $workspace->user_id,
        'workspace_id' => $workspace->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $workspace->organization_id,
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'env_file_content' => "SHARED_KEY=from-site\nSITE_ONLY=yes",
    ]);

    $manager = app(OrganizationSecretManager::class);
    $shared = $manager->create($workspace->organization, 'SHARED_KEY', 'from-secret', null);
    $secretOnly = $manager->create($workspace->organization, 'SECRET_ONLY', 'vault', null);
    $manager->link($site, $shared);
    $manager->link($site, $secretOnly);

    $map = app(DeploymentSecretInventory::class)->environmentMapForSite($site->fresh());

    expect($map['WORKSPACE_ONLY'])->toBe('ws')
        ->and($map['SECRET_ONLY'])->toBe('vault')
        ->and($map['SHARED_KEY'])->toBe('from-site')
        ->and($map['SITE_ONLY'])->toBe('yes');
});

test('composer includes linked secrets the same way', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'env_file_content' => 'APP_NAME=dply',
    ]);
    $manager = app(OrganizationSecretManager::class);
    $secret = $manager->create($org, 'VAULT_KEY', 'vault-value', null);
    $manager->link($site, $secret);

    $content = app(SiteDotEnvComposer::class)->compose($site->fresh());

    expect($content)->toContain('APP_NAME=dply')
        ->and($content)->toContain('VAULT_KEY=vault-value');
});
