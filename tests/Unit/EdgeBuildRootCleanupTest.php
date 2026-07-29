<?php

namespace Tests\Unit\EdgeBuildRootCleanupTest;

use App\Modules\Edge\Jobs\PublishEdgeDeploymentJob;
use App\Modules\Edge\Jobs\SnapshotEdgeBuildCacheJob;
use App\Modules\Edge\Services\EdgeBuildRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

/**
 * Regression cover for two coupled invariants that silently drifted apart once.
 *
 * 1. The build workdir must live under EdgeBuildRunner::buildRoot(), because the
 *    checkout is bind-mounted into the build container and the mount source has
 *    to be a Docker-shareable path.
 * 2. PublishEdgeDeploymentJob::cleanupLocalArtifact() only deletes inside that
 *    same root.
 *
 * When work_root moved off sys_get_temp_dir(), the cleanup guard still tested
 * against sys_get_temp_dir() — so it never matched, and every successful deploy
 * leaked its workdir. Nothing failed; the directories just piled up.
 */
function invokeCleanup(PublishEdgeDeploymentJob $job): void
{
    $method = new \ReflectionMethod($job, 'cleanupLocalArtifact');
    $method->setAccessible(true);
    $method->invoke($job);
}

afterEach(function (): void {
    File::deleteDirectory(EdgeBuildRunner::buildRoot().'/dply-edge-build-unit-test');
    File::deleteDirectory(sys_get_temp_dir().'/dply-edge-outside-root');
});

test('buildRoot defaults to a path inside the project, not the system temp dir', function () {
    // storage/ lives under the project tree, which macOS Docker shares by
    // default. /var/tmp — the TMPDIR a queue worker inherits — does not.
    expect(EdgeBuildRunner::buildRoot())->toBe(storage_path('app/edge-builds'));
});

test('buildRoot honours the configured work_root', function () {
    config()->set('edge.build.work_root', '/custom/edge/root');

    expect(EdgeBuildRunner::buildRoot())->toBe('/custom/edge/root');
});

test('buildRoot falls back to the system temp dir when work_root is blank', function () {
    config()->set('edge.build.work_root', '');

    expect(EdgeBuildRunner::buildRoot())->toBe(sys_get_temp_dir());
});

test('cleanup removes a workdir that lives under buildRoot', function () {
    $workRoot = EdgeBuildRunner::buildRoot().'/dply-edge-build-unit-test';
    $artifactDir = $workRoot.'/out';
    File::ensureDirectoryExists($artifactDir);
    File::put($artifactDir.'/index.html', 'hi');
    File::put($workRoot.'/build.log', 'log');

    invokeCleanup(new PublishEdgeDeploymentJob('deploy-id', $artifactDir));

    expect(is_dir($workRoot))->toBeFalse();
});

test('cleanup with cache_async keeps checkout and deletes out only', function () {
    Queue::fake();

    $workRoot = EdgeBuildRunner::buildRoot().'/dply-edge-build-unit-test';
    $src = $workRoot.'/src';
    $artifactDir = $workRoot.'/out';
    File::ensureDirectoryExists($src.'/node_modules');
    File::put($src.'/node_modules/.keep', '1');
    File::ensureDirectoryExists($artifactDir);
    File::put($artifactDir.'/index.html', 'hi');
    File::put($workRoot.'/build.log', 'log');

    invokeCleanup(new PublishEdgeDeploymentJob(
        'deploy-id',
        $artifactDir,
        null,
        null,
        [
            'site_id' => 'site-1',
            'checkout' => $src,
            'cache_key' => 'abc123',
            'checkout_root' => $src,
        ],
    ));

    expect(is_dir($src))->toBeTrue()
        ->and(is_dir($artifactDir))->toBeFalse()
        ->and(is_file($workRoot.'/build.log'))->toBeFalse();

    Queue::assertPushed(SnapshotEdgeBuildCacheJob::class);
});

test('cleanup refuses to delete a directory outside buildRoot', function () {
    // Containment: the job recursively deletes dirname($localArtifactDir), so a
    // path outside the build root must never be touched.
    config()->set('edge.build.work_root', storage_path('app/edge-builds'));

    $outside = sys_get_temp_dir().'/dply-edge-outside-root';
    $artifactDir = $outside.'/out';
    File::ensureDirectoryExists($artifactDir);

    invokeCleanup(new PublishEdgeDeploymentJob('deploy-id', $artifactDir));

    expect(is_dir($outside))->toBeTrue();
});

test('a workdir built from buildRoot is always inside the cleanup guard', function () {
    // The property that actually broke: whatever root EdgeBuildRunner builds
    // under, cleanup must recognise it. Assert it for several roots so a future
    // change to one side without the other fails here.
    foreach ([storage_path('app/edge-builds'), sys_get_temp_dir(), '/var/tmp'] as $root) {
        config()->set('edge.build.work_root', $root);

        $workRoot = rtrim(EdgeBuildRunner::buildRoot(), '/').'/dply-edge-build-abc123';

        expect(str_starts_with($workRoot, EdgeBuildRunner::buildRoot()))->toBeTrue();
    }
});
