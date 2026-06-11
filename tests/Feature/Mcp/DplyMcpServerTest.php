<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp\DplyMcpServerTest;

use App\Jobs\RunSiteDeploymentJob;
use App\Mcp\Resources\SiteListResource;
use App\Mcp\Servers\DplyServer;
use App\Mcp\Tools\Deploy\DeploySite;
use App\Mcp\Tools\Sites\GetSite;
use App\Mcp\Tools\Sites\ListSites;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Build an org with one server + site and an API token, and wire the request
 * attributes that `auth.api` (AuthenticateApiToken) would normally set so the
 * MCP tools resolve the same context they do behind the HTTP transport.
 *
 * @param  list<string>|null  $abilities
 * @return array{0: Organization, 1: Site, 2: ApiToken}
 */
function mcpContext(?array $abilities = ['*']): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'acme-app',
        'git_repository_url' => 'git@github.com:acme/app.git',
    ]);

    ['token' => $token] = ApiToken::createToken($user, $org, 'mcp-test', null, $abilities);
    $token->setRelation('organization', $org);
    $token->setRelation('user', $user);

    request()->attributes->set('api_token', $token);
    request()->attributes->set('api_organization', $org);
    test()->actingAs($user);

    return [$org, $site, $token];
}

test('list_sites returns only the org\'s sites', function (): void {
    [$org, $site] = mcpContext();

    // A site in a different org must not leak.
    $otherSite = Site::factory()->create(['name' => 'foreign-app']);

    DplyServer::tool(ListSites::class)
        ->assertOk()
        ->assertSee($site->id)
        ->assertSee('acme-app')
        ->assertDontSee($otherSite->id);
});

test('get_site rejects a site from another organization', function (): void {
    mcpContext();
    $foreign = Site::factory()->create();

    DplyServer::tool(GetSite::class, ['site_id' => $foreign->id])
        ->assertHasErrors()
        ->assertSee('not found in this organization');
});

test('deploy_site queues RunSiteDeploymentJob for a site with a repo', function (): void {
    Queue::fake();
    [, $site] = mcpContext();

    DplyServer::tool(DeploySite::class, ['site_id' => $site->id])
        ->assertOk()
        ->assertSee('queued');

    Queue::assertPushed(RunSiteDeploymentJob::class);
});

test('deploy_site is rejected for a read-only token', function (): void {
    Queue::fake();
    [, $site] = mcpContext(['sites.read']);

    DplyServer::tool(DeploySite::class, ['site_id' => $site->id])
        ->assertHasErrors()
        ->assertSee('sites.deploy');

    Queue::assertNotPushed(RunSiteDeploymentJob::class);
});

test('deploy_site refuses a site without a git repository', function (): void {
    Queue::fake();
    [, $site] = mcpContext();
    $site->update(['git_repository_url' => null]);

    DplyServer::tool(DeploySite::class, ['site_id' => $site->id])
        ->assertHasErrors()
        ->assertSee('Git repository');

    Queue::assertNotPushed(RunSiteDeploymentJob::class);
});

test('the sites resource exposes the org\'s sites', function (): void {
    [, $site] = mcpContext();

    DplyServer::resource(SiteListResource::class)
        ->assertOk()
        ->assertSee($site->id)
        ->assertSee('acme-app');
});
