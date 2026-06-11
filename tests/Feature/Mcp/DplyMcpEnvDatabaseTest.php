<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp\DplyMcpEnvDatabaseTest;

use App\Jobs\CreateSiteDatabaseJob;
use App\Jobs\PushSiteEnvJob;
use App\Mcp\Servers\DplyServer;
use App\Mcp\Tools\Database\CreateSiteDatabase;
use App\Mcp\Tools\Database\ListSiteDatabases;
use App\Mcp\Tools\Env\DeleteSiteEnvVar;
use App\Mcp\Tools\Env\GetSiteEnv;
use App\Mcp\Tools\Env\SetSiteEnvVar;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\User;
use App\Support\Servers\ServerDatabaseHostCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @param  list<string>|null  $abilities
 * @return array{0: Organization, 1: Site}
 */
function envDbContext(?array $abilities = ['*']): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'acme-app',
        'database_engine' => 'mysql',
        'env_file_content' => "APP_NAME=Acme\nAPP_KEY=base64:".base64_encode(str_repeat('a', 32))."\nMAIL_FROM_ADDRESS=old@acme.test\n",
    ]);

    ['token' => $token] = ApiToken::createToken($user, $org, 'mcp-test', null, $abilities);
    $token->setRelation('organization', $org);
    $token->setRelation('user', $user);
    request()->attributes->set('api_token', $token);
    request()->attributes->set('api_organization', $org);
    test()->actingAs($user);

    return [$org, $site];
}

test('get_site_env returns keys only by default and values when asked', function () {
    [, $site] = envDbContext();

    DplyServer::tool(GetSiteEnv::class, ['site_id' => $site->id])
        ->assertOk()
        ->assertSee('APP_NAME')
        ->assertSee('MAIL_FROM_ADDRESS')
        ->assertDontSee('old@acme.test');

    DplyServer::tool(GetSiteEnv::class, ['site_id' => $site->id, 'show_values' => true])
        ->assertOk()
        ->assertSee('old@acme.test');
});

test('set_site_env_var stages the change and queues a push', function () {
    Queue::fake();
    [, $site] = envDbContext();

    DplyServer::tool(SetSiteEnvVar::class, [
        'site_id' => $site->id,
        'key' => 'MAIL_FROM_ADDRESS',
        'value' => 'new@acme.test',
    ])->assertOk()->assertSee('env_push');

    $site->refresh();
    expect($site->env_file_content)->toContain('new@acme.test');
    expect($site->env_cache_origin)->toBe('local-edit');
    Queue::assertPushed(PushSiteEnvJob::class);
});

test('delete_site_env_var is a no-op when the key is absent', function () {
    Queue::fake();
    [, $site] = envDbContext();

    DplyServer::tool(DeleteSiteEnvVar::class, ['site_id' => $site->id, 'key' => 'NOPE_NOT_SET'])
        ->assertOk()
        ->assertSee('nothing changed');

    Queue::assertNotPushed(PushSiteEnvJob::class);
});

test('env writes are rejected for a read-only token', function () {
    [, $site] = envDbContext(['sites.read']);

    DplyServer::tool(SetSiteEnvVar::class, ['site_id' => $site->id, 'key' => 'FOO', 'value' => 'bar'])
        ->assertHasErrors()
        ->assertSee('sites.write');
});

test('create_site_database creates the row and queues the provision job', function () {
    Queue::fake();
    [, $site] = envDbContext();

    // mysql is installed on the server.
    test()->mock(ServerDatabaseHostCapabilities::class, function ($m) {
        $m->shouldReceive('forServer')->andReturn(['mysql' => true]);
    });

    DplyServer::tool(CreateSiteDatabase::class, [
        'site_id' => $site->id,
        'name' => 'acme_db',
        'engine' => 'mysql',
    ])->assertOk()->assertSee('site_db_create');

    expect(ServerDatabase::where('site_id', $site->id)->where('name', 'acme_db')->exists())->toBeTrue();
    Queue::assertPushed(CreateSiteDatabaseJob::class);
});

test('list_site_databases requires database.read', function () {
    [, $site] = envDbContext(['sites.read']);

    DplyServer::tool(ListSiteDatabases::class, ['site_id' => $site->id])
        ->assertHasErrors()
        ->assertSee('database.read');
});
