<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Scaffold\ScaffoldWordPressPipelineTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\RemoteCli\Services\SiteAuditWriter;
use App\Modules\Scaffold\Services\PlaceholderDnsManager;
use App\Modules\Scaffold\Services\PrerequisiteResult;
use App\Modules\Scaffold\Services\ScaffoldPrerequisites;
use App\Modules\Scaffold\Services\ScaffoldStep;
use App\Modules\Scaffold\Services\ScaffoldWordPressPipeline;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerDatabaseProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});
function placeholderDnsAlwaysAssigns(string $hostname = 'my-wp-blog.198-51-100-3.nip.io'): PlaceholderDnsManager
{
    $mock = Mockery::mock(PlaceholderDnsManager::class);
    $mock->shouldReceive('assign')
        ->andReturn([
            'hostname' => $hostname,
            'zone' => null,
            'record_id' => null,
            'source' => 'nip.io',
        ]);
    $mock->shouldReceive('release')->andReturnNull();

    return $mock;
}
function makeScaffoldingSite(string $serverEngine = 'mariadb114'): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['database' => $serverEngine],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'My WP Blog',
        'slug' => 'my-wp-blog',
        'status' => Site::STATUS_SCAFFOLDING,
        'meta' => [
            'scaffold' => [
                'framework' => 'wordpress',
                'admin_email' => 'admin@example.com',
            ],
        ],
    ]);
}
test('happy path walks all six steps', function () {
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->once()->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->once()->andReturn('ok');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('ok', 0, false));

    $audit = app(SiteAuditWriter::class);

    $result = (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, $audit, placeholderDnsAlwaysAssigns()))->run($site);

    expect($result['ok'])->toBeTrue();

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_PENDING);

    $steps = collect($site->meta['scaffold']['steps']);

    // 7 = original 6 + the new placeholder_dns step
    expect($steps)->toHaveCount(7);
    expect($steps->every(fn ($s) => $s['state'] === ScaffoldStep::STATE_COMPLETED))->toBeTrue();

    // Q18 hardening opinions recorded for the Hardening tab to read.
    expect($site->meta['scaffold']['hardening'])->not->toBeEmpty();
    expect($site->meta['scaffold']['hardening'])->toHaveCount(6);

    $db = ServerDatabase::query()->sole();
    expect($db->engine)->toBe('mariadb114');

    // Each opinion writes its own audit row.
    $opinionAudits = SiteAuditEvent::query()->where('action', 'scaffold_default_applied')->get();
    expect($opinionAudits)->toHaveCount(6);

    $completed = SiteAuditEvent::query()->where('action', 'scaffold_completed')->sole();
    expect($completed->payload['framework'])->toBe('wordpress');
});
test('falls back to mysql when server engine is postgres', function () {
    // Defensive: even though the wizard should disable the WordPress
    // tile on Postgres-only hosts, the pipeline guards the engine
    // pick at runtime.
    $site = makeScaffoldingSite(serverEngine: 'postgres17');

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('ok', 0, false));

    (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), placeholderDnsAlwaysAssigns()))->run($site);

    $db = ServerDatabase::query()->sole();
    expect($db->engine)->toBe('mysql84', 'Pipeline must fall back to mysql84 when server engine is incompatible');
});
test('failed wp install marks failed and audits', function () {
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);

    // First three runs succeed (download, config, install setup),
    // wp_install (4th call) fails.
    $executor->shouldReceive('runInlineBash')
        ->withArgs(fn ($s, string $name) => $name !== 'scaffold-wp:core-install')
        ->andReturn(new ProcessOutput('ok', 0, false));
    $executor->shouldReceive('runInlineBash')
        ->withArgs(fn ($s, string $name) => $name === 'scaffold-wp:core-install')
        ->andReturn(new ProcessOutput('Error: Could not connect to db', 1, false));

    $result = (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), placeholderDnsAlwaysAssigns()))->run($site);

    expect($result['ok'])->toBeFalse();
    expect($result['failed_step'])->toBe('wp_install');

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_SCAFFOLD_FAILED);

    // apply_hardening must NOT have run.
    $hardening = collect($site->meta['scaffold']['steps'])->firstWhere('key', 'apply_hardening');
    expect($hardening['state'])->toBe(ScaffoldStep::STATE_PENDING);
});
test('wp install uses placeholder hostname in url argument', function () {
    // Critical Q12 invariant: wp core install bakes the URL into
    // wp_options.siteurl + wp_options.home in serialized form, so
    // the placeholder hostname must be passed to --url= on the
    // first install rather than rewritten later via search-replace.
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureWpCli')->andReturn(PrerequisiteResult::alreadyPresent('wp-cli'));
    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

    $installBash = null;
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')
        ->withArgs(function ($s, string $name, string $bash) use (&$installBash) {
            if ($name === 'scaffold-wp:core-install') {
                $installBash = $bash;
            }

            return true;
        })
        ->andReturn(new ProcessOutput('Success: WordPress installed.', 0, false));

    $dns = placeholderDnsAlwaysAssigns(hostname: 'my-wp-blog.203-0-113-42.nip.io');

    (new ScaffoldWordPressPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), $dns))
        ->run($site);

    expect($installBash)->not->toBeNull('wp core install step should have run');
    $this->assertStringContainsString("--url='http://my-wp-blog.203-0-113-42.nip.io'", $installBash);
});
