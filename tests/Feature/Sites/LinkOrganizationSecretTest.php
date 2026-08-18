<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\LinkOrganizationSecretTest;

use App\Livewire\Sites\SiteEnvironment;
use App\Models\Organization;
use App\Models\OrganizationSecret;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Sites\OrganizationSecretManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('site updater can link and unlink an org secret', function () {
    [$user, $server, $site, $secret] = siteWithSecret();

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->assertSee(__('Linked secrets'))
        ->call('linkOrganizationSecret', $secret->id)
        ->assertHasNoErrors();

    expect($site->fresh()->organizationSecrets)->toHaveCount(1);

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->assertSee('STRIPE_SECRET')
        ->call('unlinkOrganizationSecret', $secret->id);

    expect($site->fresh()->organizationSecrets)->toHaveCount(0);
});

test('cannot link a second secret for the same key', function () {
    [$user, $server, $site, $secret] = siteWithSecret();
    $other = OrganizationSecret::factory()->create([
        'organization_id' => $site->organization_id,
        'key' => 'STRIPE_SECRET',
        'notes' => 'staging',
    ]);
    app(OrganizationSecretManager::class)->link($site, $secret);

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->call('linkOrganizationSecret', $other->id);

    expect($site->fresh()->organizationSecrets)->toHaveCount(1);
});

/**
 * @return array{0: User, 1: Server, 2: Site, 3: OrganizationSecret}
 */
function siteWithSecret(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'env_file_content' => 'APP_NAME=dply',
    ]);
    $secret = OrganizationSecret::factory()->create([
        'organization_id' => $org->id,
        'key' => 'STRIPE_SECRET',
        'value' => 'sk_test',
        'notes' => 'production',
    ]);

    return [$user, $server, $site, $secret];
}
