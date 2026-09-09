<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DatabaseUrlAliasTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Deploy\Services\Concerns\ManagesDatabaseBindings;
use App\Modules\Deploy\Services\SiteBindingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * DATABASE_URL is a Laravel/Rails convention. A linked database produced a
 * correct connection URL that a Payload app could not see — it reads
 * DATABASE_URI, and @payloadcms/db-postgres does not fall back — so the
 * auto-detected `payload migrate` step failed with no database configured.
 */
function aliasesFor(?string $migrationTool): array
{
    $site = new Site;
    $site->meta = $migrationTool === null
        ? []
        : ['vm_runtime' => ['detected' => ['language' => 'node', 'framework' => 'nextjs', 'migration_tool' => $migrationTool]]];

    $m = new \ReflectionMethod(ManagesDatabaseBindings::class, 'databaseUrlAliasesFor');
    $m->setAccessible(true);

    return $m->invoke(null, $site);
}

test('a payload app gets DATABASE_URI', function () {
    expect(aliasesFor('payload'))->toBe(['DATABASE_URI']);
});

test('prisma and drizzle need no alias', function () {
    // Both already read DATABASE_URL, which the binding sets directly.
    expect(aliasesFor('prisma'))->toBe([])
        ->and(aliasesFor('drizzle'))->toBe([]);
});

test('an unrecognised stack gets no aliases', function () {
    // Inventing env names an app never reads is noise at best, and can shadow
    // something the operator set deliberately.
    expect(aliasesFor(null))->toBe([])
        ->and(aliasesFor('something-else'))->toBe([]);
});

/**
 * Detection used to bake DATABASE_URI straight into injected_env, where the
 * operator could neither see where it came from nor remove it. It now seeds the
 * binding's editable alias map instead — once, and never over a map the
 * operator has already touched.
 */
function seedAliases(SiteBinding $binding, Site $site): void
{
    $manager = app(SiteBindingManager::class);
    $m = new \ReflectionMethod($manager, 'seedDetectedEnvAliases');
    $m->setAccessible(true);
    $m->invoke($manager, $binding, $site);
}

test('detection seeds the editable alias map instead of baking the key in', function () {
    [$site, $binding] = payloadSiteWithBinding();

    seedAliases($binding, $site);

    expect($binding->fresh()->envAliases())->toBe(['DATABASE_URL' => ['DATABASE_URI']])
        // …and it reaches the app exactly as before, via connectionEnv().
        ->and($binding->fresh()->connectionEnv())->toHaveKey('DATABASE_URI', 'postgres://u:p@127.0.0.1:5432/app');
});

test('seeding never resurrects an alias the operator deleted', function () {
    [$site, $binding] = payloadSiteWithBinding();
    // An empty map means "cleared", not "never seeded".
    $binding->forceFill(['env_customization' => ['aliases' => []]])->save();

    seedAliases($binding, $site);

    expect($binding->fresh()->envAliases())->toBe([])
        ->and($binding->fresh()->connectionEnv())->not->toHaveKey('DATABASE_URI');
});

test('seeding leaves an operator-authored map alone', function () {
    [$site, $binding] = payloadSiteWithBinding();
    $binding->forceFill(['env_customization' => ['aliases' => ['DATABASE_URL' => ['POSTGRES_URL']]]])->save();

    seedAliases($binding, $site);

    expect($binding->fresh()->envAliases())->toBe(['DATABASE_URL' => ['POSTGRES_URL']]);
});

/**
 * @return array{0: Site, 1: SiteBinding}
 */
function payloadSiteWithBinding(): array
{
    $org = Organization::factory()->create();
    $server = Server::factory()->ready()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'meta' => ['vm_runtime' => ['detected' => ['language' => 'node', 'migration_tool' => 'payload']]],
    ]);

    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'attach_existing',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'injected_env' => [
            'DB_CONNECTION' => 'pgsql',
            'DATABASE_URL' => 'postgres://u:p@127.0.0.1:5432/app',
        ],
        'config' => ['engine' => 'postgres', 'connection' => ''],
    ]);

    return [$site, $binding];
}
