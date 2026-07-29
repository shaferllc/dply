<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeBuildFailurePersistsLogTest;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Modules\Edge\Services\EdgeArtifactPublisher;
use App\Modules\Edge\Services\EdgeBuildRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;

uses(RefreshDatabase::class);

test('build failure before runner returns still persists the local build log', function () {
    Storage::fake('edge_r2');
    config(['edge.disk.name' => 'edge_r2', 'edge.fake.enabled' => false]);

    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'source' => ['repo' => 'acme/app', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
            ],
        ],
    ]);
    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_BUILDING,
        'git_branch' => 'main',
        'storage_prefix' => 'edge/'.$org->id.'/'.$site->id.'/01FAILLOG',
    ]);

    $workRoot = rtrim(EdgeBuildRunner::buildRoot(), '/').'/dply-edge-build-'.$deployment->id;
    File::ensureDirectoryExists($workRoot);
    $localLog = $workRoot.'/build.log';
    File::put($localLog, "=== dply Edge build {$deployment->id} ===\n[dply:step] clone\nclone ok\n[dply:step] build\nnpm ERR!\n");

    $deployment->update([
        'meta' => array_merge(is_array($deployment->meta) ? $deployment->meta : [], [
            'local_build_log_path' => $localLog,
        ]),
    ]);

    $runner = Mockery::mock(EdgeBuildRunner::class);
    $runner->shouldReceive('build')->once()->andThrow(new \RuntimeException('npm ci exploded'));
    app()->instance(EdgeBuildRunner::class, $runner);

    $publisher = Mockery::mock(EdgeArtifactPublisher::class)->makePartial();
    $publisher->shouldReceive('uploadFile')->once()->andReturnUsing(function (string $local, string $key, string $disk): void {
        Storage::disk($disk)->put($key, (string) file_get_contents($local));
    });
    app()->instance(EdgeArtifactPublisher::class, $publisher);

    try {
        app()->call([new BuildEdgeSiteJob($deployment->id), 'handle']);
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toBe('npm ci exploded');
    }

    $deployment->refresh();
    expect($deployment->status)->toBe(EdgeDeployment::STATUS_FAILED);
    expect($deployment->build_log_path)->toBe('edge/'.$org->id.'/'.$site->id.'/01FAILLOG/build.log');
    expect($deployment->readBuildLog($site))->toContain('npm ERR!');

    File::deleteDirectory($workRoot);
});
