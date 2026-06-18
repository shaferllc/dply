<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\Manifest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Models\SiteProcess;
use App\Models\User;
use App\Services\Deploy\Manifest\DplyManifest;
use App\Services\Deploy\Manifest\DplyManifestParser;
use App\Services\Deploy\Manifest\SiteManifestCodeShapeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function manifestSite(): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
    ]);
}

function sync(): SiteManifestCodeShapeSync
{
    return app(SiteManifestCodeShapeSync::class);
}

function manifest(string $yaml): DplyManifest
{
    return (new DplyManifestParser)->parseYaml($yaml);
}

test('reconcile creates managed build/release steps and processes', function () {
    $site = manifestSite();

    $result = sync()->reconcile($site, manifest(<<<'YAML'
    build:
      - composer install --no-dev
      - npm ci
    release:
      - php artisan migrate --force
    processes:
      worker: php artisan queue:work
    YAML));

    expect($result['build'])->toBe(2);
    expect($result['release'])->toBe(1);
    expect($result['processes'])->toBe(1);

    $build = SiteDeployStep::where('site_id', $site->id)->where('phase', 'build')->where('managed_by_manifest', true)->orderBy('sort_order')->pluck('custom_command')->all();
    expect($build)->toBe(['composer install --no-dev', 'npm ci']);

    $worker = SiteProcess::where('site_id', $site->id)->where('managed_by_manifest', true)->first();
    expect($worker->type)->toBe('worker');
    expect($worker->command)->toBe('php artisan queue:work');
});

test('a category dropped from the manifest clears its managed rows but keeps user rows', function () {
    $site = manifestSite();

    // First deploy: manifest declares a build step.
    sync()->reconcile($site, manifest("build:\n  - composer install\n"));
    // A user also adds their own (non-managed) build step.
    SiteDeployStep::create([
        'site_id' => $site->id,
        'pipeline_id' => SiteDeployStep::where('site_id', $site->id)->first()->pipeline_id,
        'phase' => 'build',
        'step_type' => 'custom',
        'custom_command' => 'echo user-step',
        'sort_order' => 5,
        'managed_by_manifest' => false,
    ]);

    // Next deploy: manifest no longer declares build.
    sync()->reconcile($site, manifest("release:\n  - php artisan migrate\n"));

    $managed = SiteDeployStep::where('site_id', $site->id)->where('phase', 'build')->where('managed_by_manifest', true)->count();
    $user = SiteDeployStep::where('site_id', $site->id)->where('custom_command', 'echo user-step')->count();

    expect($managed)->toBe(0);   // managed build step cleared
    expect($user)->toBe(1);      // user step preserved
});

test('reconcile reports a version change', function () {
    $site = manifestSite();
    $site->runtime = 'php';
    $site->runtime_version = '8.3';
    $site->save();

    $result = sync()->reconcile($site, manifest("version: \"8.4\"\n"));

    expect($result['runtime_change'])->toMatchArray(['field' => 'version', 'from' => '8.3', 'to' => '8.4']);
});

test('revertToDashboard clears all managed rows', function () {
    $site = manifestSite();
    sync()->reconcile($site, manifest("build:\n  - composer install\nprocesses:\n  worker: sidekiq\n"));

    expect(sync()->hasManagedRows($site))->toBeTrue();

    $result = sync()->revertToDashboard($site);

    expect($result['steps'])->toBeGreaterThan(0);
    expect($result['processes'])->toBe(1);
    expect(sync()->hasManagedRows($site->fresh()))->toBeFalse();
});
