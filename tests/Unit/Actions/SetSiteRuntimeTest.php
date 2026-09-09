<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\SetSiteRuntimeTest;

use App\Actions\Sites\SetSiteRuntime;
use App\Enums\SiteType;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function phpSite(): Site
{
    // A host that genuinely has both runtimes, so these tests exercise the
    // field/apply logic rather than the "runtime not installed" guard, which is
    // covered separately below.
    $server = Server::factory()->ready()->create([
        'meta' => ['php_version' => '8.3', 'runtime_defaults' => ['node' => '22']],
    ]);

    $run = \App\Models\ServerProvisionRun::create([
        'server_id' => $server->id, 'attempt' => 1, 'status' => 'succeeded',
    ]);
    \App\Models\ServerProvisionArtifact::create([
        'server_provision_run_id' => $run->id,
        'type' => 'stack_summary',
        'key' => 'stack-summary',
        'label' => 'Stack summary',
        'content' => '',
        'metadata' => ['webserver' => 'nginx', 'php_version' => '8.3'],
    ]);
    \App\Support\Servers\ServerInstalledServices::forgetStackSummary((string) $server->id);

    return Site::factory()->create([
        'server_id' => $server->id,
        'type' => SiteType::Php,
        'runtime' => 'php',
        'start_command' => null,
        'internal_port' => null,
        // A deployed site: the app-less guard below is tested separately.
        'last_deploy_at' => now(),
    ]);
}

test('switching to a proxied runtime without a start command is refused', function () {
    Queue::fake();
    $site = phpSite();

    expect(fn () => app(SetSiteRuntime::class)->handle($site, ['runtime' => 'node']))
        ->toThrow(\InvalidArgumentException::class);

    // Nothing written, nothing applied — the site must not be left half-switched
    // with a vhost proxying to a port that was never set.
    expect($site->fresh()->runtime)->toBe('php');
    Queue::assertNotPushed(ApplySiteWebserverConfigJob::class);
});

test('a complete switch writes the fields and re-applies the web server config', function () {
    Queue::fake();
    $site = phpSite();

    app(SetSiteRuntime::class)->handle($site, [
        'runtime' => 'node',
        'runtime_version' => '22',
        'start_command' => 'npm run start',
        'internal_port' => 3000,
    ]);

    $fresh = $site->fresh();
    expect($fresh->runtime)->toBe('node')
        ->and($fresh->runtime_version)->toBe('22')
        ->and($fresh->internal_port)->toBe(3000)
        ->and($fresh->type)->toBe(SiteType::Node);

    // The whole reason this action exists: the box has to be told.
    Queue::assertPushed(ApplySiteWebserverConfigJob::class);
});

test('switching back to php needs no port and still re-applies', function () {
    Queue::fake();
    $site = phpSite();
    $site->forceFill(['runtime' => 'node', 'type' => SiteType::Node, 'start_command' => 'npm start', 'internal_port' => 3000])->save();

    app(SetSiteRuntime::class)->handle($site, ['runtime' => 'php', 'runtime_version' => '8.3']);

    expect($site->fresh()->runtime)->toBe('php')
        ->and($site->fresh()->type)->toBe(SiteType::Php);
    Queue::assertPushed(ApplySiteWebserverConfigJob::class);
});

test('an unknown runtime is rejected', function () {
    Queue::fake();
    $site = phpSite();

    expect(fn () => app(SetSiteRuntime::class)->handle($site, ['runtime' => 'cobol']))
        ->toThrow(\InvalidArgumentException::class);
    Queue::assertNotPushed(ApplySiteWebserverConfigJob::class);
});

test('every mise runtime maps to a real SiteType', function () {
    foreach (SetSiteRuntime::proxiedRuntimes() as $runtime) {
        expect(SetSiteRuntime::siteTypeFor($runtime))->toBe(SiteType::Node);
    }
    expect(SetSiteRuntime::siteTypeFor('php'))->toBe(SiteType::Php)
        ->and(SetSiteRuntime::siteTypeFor('static'))->toBe(SiteType::Static);
});

test('an app-less site cannot switch to a proxied runtime', function () {
    Queue::fake();
    $server = Server::factory()->ready()->create([
        'meta' => ['runtime_defaults' => ['node' => '22'], 'php_version' => 'none'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'type' => SiteType::Php,
        'runtime' => 'php',
        'git_repository_url' => null,
        'last_deploy_at' => null,
    ]);

    // divineiv's exact state. Allowing this removed the splash page that was
    // serving 200 and published a proxy to a port nothing listens on.
    expect($site->fresh()->lacksInstalledApp())->toBeTrue();

    expect(fn () => app(SetSiteRuntime::class)->handle($site->fresh(), [
        'runtime' => 'node',
        'start_command' => 'npm run start',
        'internal_port' => 3000,
    ]))->toThrow(\InvalidArgumentException::class);

    expect($site->fresh()->runtime)->toBe('php');
    Queue::assertNotPushed(ApplySiteWebserverConfigJob::class);
});

test('a runtime the server does not have is refused', function () {
    Queue::fake();
    $server = Server::factory()->ready()->create([
        'meta' => ['runtime_defaults' => ['node' => '22'], 'php_version' => 'none'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'type' => SiteType::Php,
        'runtime' => 'php',
        'last_deploy_at' => now(),
    ]);

    // Go is in the catalog but not on this box. The picker disables it; the CLI
    // path had no equivalent check.
    expect(fn () => app(SetSiteRuntime::class)->handle($site->fresh(), [
        'runtime' => 'go',
        'start_command' => './server',
        'internal_port' => 8080,
    ]))->toThrow(\InvalidArgumentException::class);

    Queue::assertNotPushed(ApplySiteWebserverConfigJob::class);
});
