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

test('site updater can paste a secret and it renders write-never', function () {
    [$user, $server, $site] = siteWithSecret();

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->set('pasteSecretKey', 'SLACK_BOT_TOKEN')
        ->set('pasteSecretValue', 'xoxb-never-echo-this')
        ->call('pasteOrganizationSecret')
        ->assertHasNoErrors()
        ->assertSet('pasteSecretValue', '')
        ->assertDontSee('xoxb-never-echo-this')
        ->assertSee('SLACK_BOT_TOKEN');

    $secret = $site->fresh()->organizationSecrets()->first();
    expect($secret)->not->toBeNull()
        ->and($secret->key)->toBe('SLACK_BOT_TOKEN')
        ->and($secret->value)->toBe('xoxb-never-echo-this');
});

test('site updater can paste KEY=value lines', function () {
    [$user, $server, $site] = siteWithSecret();

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->set('pasteSecretBlob', "SLACK_BOT_TOKEN=xoxb-one\nGOOGLE_DRIVE_CLIENT_ID=drive-two")
        ->call('pasteOrganizationSecret')
        ->assertHasNoErrors()
        ->assertDontSee('xoxb-one')
        ->assertDontSee('drive-two')
        ->assertSee('SLACK_BOT_TOKEN')
        ->assertSee('GOOGLE_DRIVE_CLIENT_ID');

    expect($site->fresh()->organizationSecrets)->toHaveCount(2);
});

test('site updater can bulk import a sectioned .env snippet', function () {
    [$user, $server, $site] = siteWithSecret();
    $snippet = <<<'ENV'
#==============================================================================
# Discord
#==============================================================================
DISCORD_CLIENT_ID=id-one
DISCORD_CLIENT_SECRET=secret-one
DISCORD_BOT_TOKEN=bot-one
DISCORD_REDIRECT_URI=${APP_URL}/notifications/oauth/discord/callback

#==============================================================================
# Telegram
#==============================================================================
TELEGRAM_BOT_TOKEN=bot-two
TELEGRAM_WEBHOOK_SECRET=secret-two
TELEGRAM_WEBHOOK_URL=${APP_URL}/hooks/telegram
ENV;

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->set('pasteSecretBlob', $snippet)
        ->assertSee('DISCORD_CLIENT_ID')
        ->assertSee('TELEGRAM_BOT_TOKEN')
        ->assertSee('Discord')
        ->assertSee('Telegram')
        ->assertDontSee('secret-one')
        ->assertDontSee('bot-two')
        ->call('pasteOrganizationSecret')
        ->assertHasNoErrors()
        ->assertDontSee('secret-one');

    $keys = $site->fresh()->organizationSecrets()->pluck('organization_secrets.key')->sort()->values()->all();
    expect($keys)->toBe([
        'DISCORD_BOT_TOKEN',
        'DISCORD_CLIENT_ID',
        'DISCORD_CLIENT_SECRET',
        'DISCORD_REDIRECT_URI',
        'TELEGRAM_BOT_TOKEN',
        'TELEGRAM_WEBHOOK_SECRET',
        'TELEGRAM_WEBHOOK_URL',
    ]);
});

test('bulk import skips keys already linked on the site', function () {
    [$user, $server, $site, $secret] = siteWithSecret();
    app(OrganizationSecretManager::class)->link($site, $secret);

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->set('pasteSecretBlob', "STRIPE_SECRET=sk_another\nNEW_APP_KEY=fresh-one")
        ->call('pasteOrganizationSecret')
        ->assertHasNoErrors()
        ->assertSee('NEW_APP_KEY');

    expect($site->fresh()->organizationSecrets()->pluck('organization_secrets.key')->sort()->values()->all())
        ->toBe(['NEW_APP_KEY', 'STRIPE_SECRET']);
});

test('cannot paste a second secret for a key already linked on the site', function () {
    [$user, $server, $site, $secret] = siteWithSecret();
    app(OrganizationSecretManager::class)->link($site, $secret);

    Livewire::actingAs($user)
        ->test(SiteEnvironment::class, ['server' => $server, 'site' => $site])
        ->set('pasteSecretKey', 'STRIPE_SECRET')
        ->set('pasteSecretValue', 'sk_another')
        ->call('pasteOrganizationSecret');

    expect($site->fresh()->organizationSecrets)->toHaveCount(1)
        ->and(OrganizationSecret::query()->where('organization_id', $site->organization_id)->count())->toBe(1);
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
