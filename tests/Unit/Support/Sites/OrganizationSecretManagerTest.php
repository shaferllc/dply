<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites\OrganizationSecretManagerTest;

use App\Models\Organization;
use App\Models\OrganizationSecret;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Sites\OrganizationSecretException;
use App\Support\Sites\OrganizationSecretManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('parsePastedEnv reads KEY=value lines and skips comments', function () {
    $manager = app(OrganizationSecretManager::class);

    expect($manager->parsePastedEnv(<<<'ENV'
# comment
SLACK_BOT_TOKEN=xoxb-one
GOOGLE_DRIVE_CLIENT_ID="drive-two"

EMPTY=
not a pair
ENV))->toBe([
        ['key' => 'SLACK_BOT_TOKEN', 'value' => 'xoxb-one', 'note' => 'comment'],
        ['key' => 'GOOGLE_DRIVE_CLIENT_ID', 'value' => 'drive-two', 'note' => 'comment'],
    ]);
});

test('parsePastedEnv last value wins for a repeated key', function () {
    $manager = app(OrganizationSecretManager::class);

    expect($manager->parsePastedEnv("FOO=one\nFOO=two"))->toBe([
        ['key' => 'FOO', 'value' => 'two', 'note' => null],
    ]);
});

test('parsePastedEnv imports a sectioned .env snippet', function () {
    $manager = app(OrganizationSecretManager::class);

    $pairs = $manager->parsePastedEnv(<<<'ENV'
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
ENV);

    expect($pairs)->toHaveCount(7)
        ->and(array_column($pairs, 'key'))->toBe([
            'DISCORD_CLIENT_ID',
            'DISCORD_CLIENT_SECRET',
            'DISCORD_BOT_TOKEN',
            'DISCORD_REDIRECT_URI',
            'TELEGRAM_BOT_TOKEN',
            'TELEGRAM_WEBHOOK_SECRET',
            'TELEGRAM_WEBHOOK_URL',
        ])
        ->and($pairs[0]['note'])->toBe('Discord')
        ->and($pairs[3]['value'])->toBe('${APP_URL}/notifications/oauth/discord/callback')
        ->and($pairs[4]['note'])->toBe('Telegram');
});

test('keys may collide when notes are provided', function () {
    $org = Organization::factory()->create();
    $manager = app(OrganizationSecretManager::class);

    $first = $manager->create($org, 'STRIPE_SECRET', 'one', null);
    $second = $manager->create($org, 'STRIPE_SECRET', 'two', 'staging');

    expect($first->id)->not->toBe($second->id)
        ->and($first->key)->toBe('STRIPE_SECRET')
        ->and($second->key)->toBe('STRIPE_SECRET')
        ->and($second->notes)->toBe('staging');
});

test('notes are required when the key already exists', function () {
    $org = Organization::factory()->create();
    $manager = app(OrganizationSecretManager::class);
    $manager->create($org, 'STRIPE_SECRET', 'one', null);

    $manager->create($org, 'STRIPE_SECRET', 'two', null);
})->throws(OrganizationSecretException::class);

test('a site cannot link two secrets with the same key', function () {
    $org = Organization::factory()->create();
    $site = siteInOrg($org);
    $manager = app(OrganizationSecretManager::class);
    $first = $manager->create($org, 'STRIPE_SECRET', 'one', null);
    $second = $manager->create($org, 'STRIPE_SECRET', 'two', 'other');

    $manager->link($site, $first);

    expect(fn () => $manager->link($site, $second))
        ->toThrow(OrganizationSecretException::class);
});

test('value is encrypted at rest and readable after rotate', function () {
    $org = Organization::factory()->create();
    $manager = app(OrganizationSecretManager::class);
    $secret = $manager->create($org, 'API_TOKEN', 'plain-one', null);

    $raw = DB::table('organization_secrets')->where('id', $secret->id)->value('value');
    expect($raw)->not->toBe('plain-one');

    $manager->rotate($secret->fresh(), 'plain-two');
    expect($secret->fresh()->value)->toBe('plain-two');
});

test('delete unlinks every site', function () {
    $org = Organization::factory()->create();
    $site = siteInOrg($org);
    $manager = app(OrganizationSecretManager::class);
    $secret = $manager->create($org, 'API_TOKEN', 'x', null);
    $manager->link($site, $secret);

    $manager->delete($secret);

    expect($site->fresh()->organizationSecrets)->toHaveCount(0)
        ->and(OrganizationSecret::query()->whereKey($secret->id)->exists())->toBeFalse();
});

function siteInOrg(Organization $org): Site
{
    $user = User::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);
}
