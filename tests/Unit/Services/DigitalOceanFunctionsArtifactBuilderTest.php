<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DigitalOceanFunctionsArtifactBuilderTest;

use App\Models\Site;
use App\Modules\Deploy\Services\DigitalOceanFunctionsArtifactBuilder;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ZipArchive;

test('it clones builds and packages a functions repo', function () {
    $root = storage_path('framework/testing/functions-builder-'.uniqid());
    $origin = $root.'/origin';

    File::ensureDirectoryExists($origin);
    runProcess(['git', 'init', '-b', 'main'], $origin);
    File::put($origin.'/README.md', "hello\n");
    runProcess(['git', 'add', '.'], $origin);
    runProcess(['git', 'config', 'user.email', 'tests@example.com'], $origin);
    runProcess(['git', 'config', 'user.name', 'Tests'], $origin);
    runProcess(['git', 'commit', '-m', 'Initial commit'], $origin);

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

    $builder = app(DigitalOceanFunctionsArtifactBuilder::class);

    $result = $builder->build($site);

    expect($result['artifact_path'])->toBeFile();
    $this->assertStringContainsString('functions-site', basename($result['artifact_path']));

    $zip = new ZipArchive;
    expect($zip->open($result['artifact_path']) === true)->toBeTrue();
    $this->assertNotFalse($zip->locateName('index.js'));
    $zip->close();

    File::deleteDirectory($root);
    File::deleteDirectory(storage_path('app/functions-builds/'.$site->id));
    File::deleteDirectory(storage_path('app/serverless-artifacts/'.$site->id));
});
/**
 * @param  list<string>  $command
 */
function runProcess(array $command, string $workingDirectory): void
{
    $process = new Process($command, $workingDirectory);
    $process->setTimeout(60);
    $process->run();

    expect($process->isSuccessful())->toBeTrue(trim($process->getErrorOutput()."\n".$process->getOutput()));
}
