<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteEnvPusherSharedSecretsTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteEnvPusher;
use App\Support\Sites\OrganizationSecretManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('standalone env push compose omits linked vault secrets', function () {
    [$site] = siteWithLinkedSecret();

    $map = app(SiteEnvPusher::class)->composeVariables($site, includeSharedSecrets: false);

    expect($map)->toHaveKey('SITE_KEY')
        ->and($map)->not->toHaveKey('VAULT_KEY');
});

test('deploy compose includes linked vault secrets under the site env', function () {
    [$site] = siteWithLinkedSecret();

    $map = app(SiteEnvPusher::class)->composeVariables($site, includeSharedSecrets: true);

    expect($map['VAULT_KEY'])->toBe('vault-value')
        ->and($map['SITE_KEY'])->toBe('from-site');
});

/**
 * @return array{0: Site}
 */
function siteWithLinkedSecret(): array
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'ssh_private_key' => 'fake',
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'env_file_content' => 'SITE_KEY=from-site',
    ]);
    $manager = app(OrganizationSecretManager::class);
    $secret = $manager->create($org, 'VAULT_KEY', 'vault-value', null);
    $manager->link($site, $secret);

    return [$site->fresh()];
}
