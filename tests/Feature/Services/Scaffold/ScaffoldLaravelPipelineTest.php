<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Scaffold\ScaffoldLaravelPipelineTest;
use Mockery;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\RemoteCli\SiteAuditWriter;
use App\Services\Scaffold\PlaceholderDnsManager;
use App\Services\Scaffold\PrerequisiteResult;
use App\Services\Scaffold\ScaffoldLaravelPipeline;
use App\Services\Scaffold\ScaffoldPrerequisites;
use App\Services\Scaffold\ScaffoldStep;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerDatabaseProvisioner;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});
/**
 * Mock PlaceholderDnsManager that records the call and returns a
 * stable nip.io-shaped hostname so downstream URL writes are
 * deterministic + the test never makes a real HTTP request.
 */
function placeholderDnsAlwaysAssigns(Site $site, string $hostname = 'my-laravel-app.198-51-100-7.nip.io'): PlaceholderDnsManager
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
function makeScaffoldingSite(): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['database' => 'mysql84'],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'My Laravel App',
        'slug' => 'my-laravel-app',
        'status' => Site::STATUS_SCAFFOLDING,
        'meta' => [
            'scaffold' => [
                'framework' => 'laravel',
                'admin_email' => 'admin@example.com',
            ],
        ],
    ]);
}
test('happy path walks all steps and settles pending', function () {
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureComposer')
        ->once()
        ->andReturn(PrerequisiteResult::alreadyPresent('composer'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')
        ->once()
        ->andReturn('CREATE DATABASE ok');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('ok', 0, false));

    $audit = app(SiteAuditWriter::class);

    $result = (new ScaffoldLaravelPipeline($prereqs, $dbProvisioner, $executor, $audit, placeholderDnsAlwaysAssigns($site)))->run($site);

    expect($result['ok'])->toBeTrue();
    expect($result['failed_step'])->toBeNull();

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_PENDING);

    $steps = collect($site->meta['scaffold']['steps']);

    // 9 = original 8 + the new placeholder_dns step (PR-follow-up wiring)
    expect($steps)->toHaveCount(9);
    expect($steps->every(fn ($s) => $s['state'] === ScaffoldStep::STATE_COMPLETED))->toBeTrue('All steps should have settled to completed; got: '.json_encode($steps->pluck('state')));

    // Admin password recorded encrypted.
    expect($site->meta['scaffold']['admin_password'])->not->toBeEmpty();
    expect(strlen(decrypt($site->meta['scaffold']['admin_password'])))->toBe(20);

    // Database row created.
    $db = ServerDatabase::query()->sole();
    expect($db->name)->toBe('dply_my_laravel_app');
    expect($db->engine)->toBe('mysql84');

    // Audit event for the success.
    $event = SiteAuditEvent::query()->where('action', 'scaffold_completed')->sole();
    expect($event->result_status)->toBe(SiteAuditEvent::RESULT_SUCCESS);
});
test('failed prereq marks site failed and audits', function () {
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureComposer')
        ->once()
        ->andReturn(PrerequisiteResult::failed('composer', 'no internet'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldNotReceive('createOnServer');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);

    $audit = app(SiteAuditWriter::class);

    $result = (new ScaffoldLaravelPipeline($prereqs, $dbProvisioner, $executor, $audit, placeholderDnsAlwaysAssigns($site)))->run($site);

    expect($result['ok'])->toBeFalse();
    expect($result['failed_step'])->toBe('prereqs');
    $this->assertStringContainsString('no internet', $result['error']);

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_SCAFFOLD_FAILED);

    $failedStep = collect($site->meta['scaffold']['steps'])->firstWhere('key', 'prereqs');
    expect($failedStep['state'])->toBe(ScaffoldStep::STATE_FAILED);

    // db_create stays pending — pipeline aborted before that step.
    $dbStep = collect($site->meta['scaffold']['steps'])->firstWhere('key', 'db_create');
    expect($dbStep['state'])->toBe(ScaffoldStep::STATE_PENDING);

    $event = SiteAuditEvent::query()->where('action', 'scaffold_failed')->sole();
    expect($event->payload['step'])->toBe('prereqs');
});
test('executor failure aborts at that step', function () {
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureComposer')->andReturn(PrerequisiteResult::alreadyPresent('composer'));

    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);

    // composer create-project blows up — simulate exit 2.
    $executor->shouldReceive('runInlineBash')
        ->once()
        ->withArgs(fn ($s, string $name) => $name === 'scaffold-laravel:composer-create')
        ->andReturn(new ProcessOutput('disk full', 2, false));

    $audit = app(SiteAuditWriter::class);

    $result = (new ScaffoldLaravelPipeline($prereqs, $dbProvisioner, $executor, $audit, placeholderDnsAlwaysAssigns($site)))->run($site);

    expect($result['ok'])->toBeFalse();
    expect($result['failed_step'])->toBe('composer_create');

    $site->refresh();
    expect($site->status)->toBe(Site::STATUS_SCAFFOLD_FAILED);
});
test('sqlite install records a server database row', function () {
    $site = makeScaffoldingSite();

    // Force the server's installed-stack to SQLite so the pipeline
    // takes the file-based branch instead of provisioning MySQL.
    $site->server->update(['meta' => ['database' => 'sqlite3']]);

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureComposer')->andReturn(PrerequisiteResult::alreadyPresent('composer'));

    // Pipeline should NOT call createOnServer for SQLite — Laravel's
    // migrate handles the file. Only the row is created.
    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldNotReceive('createOnServer');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('ok', 0, false));

    $audit = app(SiteAuditWriter::class);

    (new ScaffoldLaravelPipeline($prereqs, $dbProvisioner, $executor, $audit, placeholderDnsAlwaysAssigns($site)))
        ->run($site);

    $db = ServerDatabase::query()->where('engine', 'sqlite')->sole();
    expect($db->name)->toBe('dply_my_laravel_app');
    expect($db->server_id)->toBe($site->server->id);
    expect($db->host)->toBe('/home/dply/my-laravel-app/current/database/database.sqlite');

    $site->refresh();
    expect($site->meta['scaffold']['database']['server_database_id'])->toBe($db->id);
});
test('placeholder dns step creates primary site domain', function () {
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureComposer')->andReturn(PrerequisiteResult::alreadyPresent('composer'));
    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('ok', 0, false));

    $dns = placeholderDnsAlwaysAssigns($site, hostname: 'my-laravel-app.203-0-113-7.nip.io');

    (new ScaffoldLaravelPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), $dns))
        ->run($site);

    $domain = $site->fresh()->primaryDomain();
    expect($domain)->not->toBeNull('Pipeline must persist a primary SiteDomain row from the placeholder hostname');
    expect($domain->hostname)->toBe('my-laravel-app.203-0-113-7.nip.io');
    expect($domain->is_primary)->toBeTrue();
});
test('write env uses placeholder hostname in app url', function () {
    $site = makeScaffoldingSite();

    $prereqs = Mockery::mock(ScaffoldPrerequisites::class);
    $prereqs->shouldReceive('ensureComposer')->andReturn(PrerequisiteResult::alreadyPresent('composer'));
    $dbProvisioner = Mockery::mock(ServerDatabaseProvisioner::class);
    $dbProvisioner->shouldReceive('createOnServer')->andReturn('ok');

    $hostnameSeen = null;
    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);

    // Capture the bash that write_env runs so we can grep for APP_URL.
    $executor->shouldReceive('runInlineBash')
        ->withArgs(function ($s, string $name, string $bash) use (&$hostnameSeen) {
            if ($name === 'scaffold-laravel:write-env') {
                $hostnameSeen = $bash;
            }

            return true;
        })
        ->andReturn(new ProcessOutput('ok', 0, false));

    $dns = placeholderDnsAlwaysAssigns($site, hostname: 'my-laravel-app.198-51-100-9.nip.io');

    (new ScaffoldLaravelPipeline($prereqs, $dbProvisioner, $executor, app(SiteAuditWriter::class), $dns))
        ->run($site);

    expect($hostnameSeen)->not->toBeNull('write_env step should have run');
    $this->assertStringContainsString('APP_URL=http://my-laravel-app.198-51-100-9.nip.io', $hostnameSeen);
});
