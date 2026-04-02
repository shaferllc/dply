<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Services\Deploy\LocalRuntimeWorkspace;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LocalRuntimeWorkspaceTest extends TestCase
{
    public function test_it_uses_the_runtime_repository_subdirectory_as_the_working_directory(): void
    {
        [$origin, $remote] = $this->makeRepository([
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

        $this->assertStringEndsWith('/repo/apps/web', $workspace['working_directory']);
        $this->assertDirectoryExists($workspace['working_directory']);
        $this->assertFileExists($workspace['working_directory'].'/package.json');
        $this->assertNotEmpty($workspace['revision']);

        File::deleteDirectory($workspace['workspace_path']);
        File::deleteDirectory($origin);
        File::deleteDirectory($remote);
    }

    public function test_it_raises_a_clear_error_when_the_runtime_repository_subdirectory_is_missing(): void
    {
        [$origin, $remote] = $this->makeRepository([
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
    }

    /**
     * @param  array<string, string>  $files
     * @return array{string, string}
     */
    private function makeRepository(array $files): array
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
}
