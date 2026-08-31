<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteBindingEnvCustomizationTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Deploy\Services\SiteBindingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>|null  $customization
 */
function pgBinding(?array $customization = null, string $status = SiteBinding::STATUS_CONFIGURED): SiteBinding
{
    $org = Organization::factory()->create();
    $server = Server::factory()->ready()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);

    return SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'attach_existing',
        'status' => $status,
        'name' => 'primary',
        'injected_env' => $status === SiteBinding::STATUS_CONFIGURED ? [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'databio',
            'DB_USERNAME' => 'databio_r0ld',
            'DB_PASSWORD' => 'sekret',
            'DATABASE_URL' => 'postgres://databio_r0ld:sekret@127.0.0.1:5432/databio',
        ] : [],
        'config' => ['engine' => 'postgres', 'connection' => ''],
        'env_customization' => $customization,
    ]);
}

test('an alias injects the same value under an extra name and keeps the original', function () {
    $binding = pgBinding(['aliases' => ['DATABASE_URL' => ['DATABASE_URI', 'POSTGRES_URL']]]);

    $env = $binding->connectionEnv();

    expect($env)
        ->toHaveKey('DATABASE_URL', 'postgres://databio_r0ld:sekret@127.0.0.1:5432/databio')
        ->toHaveKey('DATABASE_URI', 'postgres://databio_r0ld:sekret@127.0.0.1:5432/databio')
        ->toHaveKey('POSTGRES_URL', 'postgres://databio_r0ld:sekret@127.0.0.1:5432/databio');
});

test('an alias never overwrites a name the binding already provides', function () {
    $binding = pgBinding(['aliases' => ['DB_PORT' => ['DB_HOST']]]);

    // DB_HOST is a real key of this binding — the alias must not clobber it.
    expect($binding->connectionEnv())->toHaveKey('DB_HOST', '127.0.0.1');
});

test('an alias for a key the binding does not provide is inert', function () {
    $binding = pgBinding(['aliases' => ['DB_HSOT' => ['PGHOST']]]);

    expect($binding->connectionEnv())->not->toHaveKey('PGHOST');
});

test('an override replaces the value of a key the binding owns', function () {
    $binding = pgBinding(['overrides' => ['DB_HOST' => '10.0.0.7']]);

    expect($binding->connectionEnv())->toHaveKey('DB_HOST', '10.0.0.7');
});

test('an override cannot introduce a key the binding does not own', function () {
    $binding = pgBinding(['overrides' => ['SOME_OTHER_KEY' => 'nope']]);

    expect($binding->connectionEnv())->not->toHaveKey('SOME_OTHER_KEY');
});

test('an alias carries the overridden value, not the generated one', function () {
    $binding = pgBinding([
        'overrides' => ['DB_HOST' => '10.0.0.7'],
        'aliases' => ['DB_HOST' => ['PGHOST']],
    ]);

    expect($binding->connectionEnv())->toHaveKey('PGHOST', '10.0.0.7');
});

test('an alias of a secret key is reported sensitive even when its name looks harmless', function () {
    $binding = pgBinding(['aliases' => [
        'DATABASE_URL' => ['POSTGRES_URL'],
        'DB_PASSWORD' => ['MY_DB_PW'],
        'DB_PORT' => ['PGPORT'],
    ]]);

    $sensitive = $binding->sensitiveEnvKeys();

    // POSTGRES_URL and MY_DB_PW match no secret name pattern, but they carry a
    // password — masking must come from the binding, not the key name.
    expect($sensitive)
        ->toContain('POSTGRES_URL')
        ->toContain('MY_DB_PW')
        ->toContain('DB_PASSWORD')
        ->toContain('DATABASE_URL');

    // PGPORT aliases DB_PORT, which EnvImportSources::KEEP exempts as config
    // rather than a secret — the alias inherits that, so it stays unmasked.
    expect($sensitive)
        ->not->toContain('PGPORT')
        ->not->toContain('DB_CONNECTION');
});

test('customization is encrypted at rest', function () {
    $binding = pgBinding(['overrides' => ['DB_PASSWORD' => 'plaintext-would-leak']]);

    $raw = (string) DB::table('site_bindings')->where('id', $binding->id)->value('env_customization');

    expect($raw)->not->toContain('plaintext-would-leak');
});

test('an empty alias map is distinguishable from never having been seeded', function () {
    expect(pgBinding(null)->hasEnvAliasMap())->toBeFalse()
        ->and(pgBinding(['aliases' => []])->hasEnvAliasMap())->toBeTrue();
});

test('expectedEnvKeys answers from the key list while a binding is still provisioning', function () {
    $binding = pgBinding(null, SiteBinding::STATUS_PROVISIONING);

    expect($binding->connectionEnv())->toBe([]);

    // The mapping editor needs rows during the multi-minute DB VM window.
    expect(app(SiteBindingManager::class)->expectedEnvKeys($binding))
        ->toContain('DB_HOST')
        ->toContain('DB_PASSWORD')
        ->toContain('DATABASE_URL')
        ->toContain('DB_CONNECTION');
});

test('expectedEnvKeys answers from real keys once the binding is configured', function () {
    $binding = pgBinding(['aliases' => ['DATABASE_URL' => ['DATABASE_URI']]]);

    expect(app(SiteBindingManager::class)->expectedEnvKeys($binding))
        ->toContain('DATABASE_URI');
});
