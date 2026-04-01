<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Services\Deploy\DigitalOceanFunctionsArtifactBuilder;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class DigitalOceanFunctionsArtifactBuilderTest extends TestCase
{
    public function test_it_clones_builds_and_packages_a_functions_repo(): void
    {
        $root = storage_path('framework/testing/functions-builder-'.uniqid());
        $origin = $root.'/origin';

        File::ensureDirectoryExists($origin);
        $this->runProcess(['git', 'init', '-b', 'main'], $origin);
        File::put($origin.'/README.md', "hello\n");
        $this->runProcess(['git', 'add', '.'], $origin);
        $this->runProcess(['git', 'config', 'user.email', 'tests@example.com'], $origin);
        $this->runProcess(['git', 'config', 'user.name', 'Tests'], $origin);
        $this->runProcess(['git', 'commit', '-m', 'Initial commit'], $origin);

        $site = new Site([
            'name' => 'Functions Site',
            'slug' => 'functions-site',
            'git_repository_url' => $origin,
            'git_branch' => 'main',
            'meta' => [
                'runtime_profile' => 'digitalocean_functions_web',
                'digitalocean_functions' => [
                    'build_command' => 'mkdir -p dist && printf "exports.main = true;\n" > dist/index.js',
                    'artifact_output_path' => 'dist',
                ],
            ],
        ]);
        $site->id = 'functions-builder-test';

        $builder = new DigitalOceanFunctionsArtifactBuilder(new SourceControlRepositoryBrowser);

        $result = $builder->build($site);

        $this->assertFileExists($result['artifact_path']);
        $this->assertStringContainsString('functions-site', basename($result['artifact_path']));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['artifact_path']) === true);
        $this->assertNotFalse($zip->locateName('index.js'));
        $zip->close();

        File::deleteDirectory($root);
        File::deleteDirectory(storage_path('app/functions-builds/'.$site->id));
        File::deleteDirectory(storage_path('app/serverless-artifacts/'.$site->id));
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, string $workingDirectory): void
    {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout(60);
        $process->run();

        $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()."\n".$process->getOutput()));
    }
}
