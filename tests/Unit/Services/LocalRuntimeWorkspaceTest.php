<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LocalRuntimeWorkspaceTest;

use App\Models\Site;
use App\Modules\Deploy\Services\LocalRuntimeWorkspace;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

test('it uses the runtime repository subdirectory as the working directory', function () {
    [$origin, $remote] = makeRepository([
        'apps/web/package.json' => json_encode([
            'name' => 'demo',
            'scripts' => ['start' => 'node server.js'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
    ]);

    $site = new Site([
        'id' => '01testlocalruntimeworkspace0001',
        'git_repository_url' => $remote,
        'git_branch' => 'master',
        'meta' => [
            'docker_runtime' => [
                'repository_subdirectory' => 'apps/web',
            ],
        ],
    ]);

    $workspace = app(LocalRuntimeWorkspace::class)->ensure($site);

    expect($workspace['working_directory'])->toEndWith('/repo/apps/web');
    expect($workspace['working_directory'])->toBeDirectory();
    expect($workspace['working_directory'].'/package.json')->toBeFile();
    expect($workspace['revision'])->not->toBeEmpty();

    File::deleteDirectory($workspace['workspace_path']);
    File::deleteDirectory($origin);
    File::deleteDirectory($remote);
});
test('it raises a clear error when the runtime repository subdirectory is missing', function () {
    [$origin, $remote] = makeRepository([
        'README.md' => "# Demo\n",
    ]);

    $site = new Site([
        'id' => '01testlocalruntimeworkspace0002',
        'git_repository_url' => $remote,
        'git_branch' => 'master',
        'meta' => [
            'docker_runtime' => [
                'repository_subdirectory' => 'apps/web',
            ],
        ],
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Local runtime repository subdirectory does not exist: apps/web');

    try {
        app(LocalRuntimeWorkspace::class)->ensure($site);
    } finally {
        File::deleteDirectory(storage_path('app/local-runtimes/'.$site->getKey()));
        File::deleteDirectory($origin);
        File::deleteDirectory($remote);
    }
});
/**
 * @param  array<string, string>  $files
 * @return array{string, string}
 */
function makeRepository(array $files): array
{
    $origin = storage_path('framework/testing/local-runtime-workspace-origin-'.uniqid());
    $remote = storage_path('framework/testing/local-runtime-workspace-remote-'.uniqid().'.git');

    File::ensureDirectoryExists($origin);

    (new Process(['git', 'init', '-b', 'master'], $origin))->mustRun();
    (new Process(['git', 'config', 'user.email', 'tests@example.com'], $origin))->mustRun();
    (new Process(['git', 'config', 'user.name', 'Tests'], $origin))->mustRun();

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($origin.'/'.$path));
        File::put($origin.'/'.$path, $contents);
    }

    (new Process(['git', 'add', '.'], $origin))->mustRun();
    (new Process(['git', 'commit', '-m', 'Initial commit'], $origin))->mustRun();
    (new Process(['git', 'clone', '--bare', $origin, $remote]))->mustRun();

    return [$origin, $remote];
}
