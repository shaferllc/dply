<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy\DigitalOceanFunctionsLaravelBuildTest;
use ZipArchive;

use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\DigitalOceanFunctionsArtifactBuilder;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it injects the laravel adapter into a digitalocean functions build', function () {
    $root = storage_path('framework/testing/do-fn-laravel-'.uniqid());
    $origin = $root.'/origin';
    File::ensureDirectoryExists($origin);

    // A repo that detects as Laravel — laravel/framework in composer.json.
    File::put($origin.'/composer.json', json_encode([
        'name' => 'demo/laravel-fn',
        'require' => ['laravel/framework' => '^13.0'],
    ], JSON_PRETTY_PRINT));

    // A .dplyignore custom exclusion plus a file it should drop.
    File::put($origin.'/.dplyignore', "# build noise\nsecret-notes.txt\n");
    File::put($origin.'/secret-notes.txt', 'shh');

    runProcess(['git', 'init', '-b', 'main'], $origin);
    runProcess(['git', 'config', 'user.email', 'tests@example.com'], $origin);
    runProcess(['git', 'config', 'user.name', 'Tests'], $origin);
    runProcess(['git', 'add', '.'], $origin);
    runProcess(['git', 'commit', '-m', 'init'], $origin);

    $server = Server::factory()->create([
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
        'name' => 'Laravel Function',
        'slug' => 'laravel-function',
        'git_repository_url' => $origin,
        'git_branch' => 'main',
        'status' => Site::STATUS_FUNCTIONS_CONFIGURED,
        'post_deploy_command' => 'printf "deploy-ran" > deploy-marker.txt',
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            // `true` stands in for `composer install` so the test stays
            // offline — the adapter injection runs regardless.
            'digitalocean_functions' => [
                'build_command' => 'true',
                'artifact_output_path' => '.',
            ],
        ],
    ]);

    $result = app(DigitalOceanFunctionsArtifactBuilder::class)->build($site);

    expect($result['artifact_path'])->toBeFile();
    $this->assertStringContainsString('Injected DigitalOcean Functions Laravel adapter', $result['output']);

    $zip = new ZipArchive;
    expect($zip->open($result['artifact_path']) === true)->toBeTrue();
    $this->assertNotFalse($zip->locateName('index.php'), 'adapter index.php should be in the artifact');
    $handler = (string) $zip->getFromName('index.php');
    $this->assertNotFalse($zip->locateName('.env'), 'managed .env should be in the artifact');
    $env = (string) $zip->getFromName('.env');
    $this->assertNotFalse($zip->locateName('deploy-marker.txt'), 'the deploy command should have run');
    $this->assertNotFalse($zip->locateName('composer.json'), 'app files should still be packaged');

    // Artifact slimming — VCS metadata and .dplyignore entries are dropped.
    expect($zip->locateName('.git/HEAD'))->toBeFalse('.git must not be in the artifact');
    expect($zip->locateName('secret-notes.txt'))->toBeFalse('.dplyignore entries must be excluded');
    $zip->close();
    $this->assertStringContainsString('function main(array $args)', $handler);

    // A Laravel function is given a stable, managed APP_KEY.
    expect($env)->toMatch('/APP_KEY=base64:.+/');
    expect((string) $site->fresh()->env_file_content)->toMatch('/APP_KEY=base64:.+/');

    // The deployer reads entrypoint as OpenWhisk `exec.main`.
    expect($site->fresh()->serverlessResolvedConfig()['entrypoint'])->toBe('main');

    File::deleteDirectory($root);
    File::deleteDirectory(storage_path('app/serverless-artifacts/'.$site->id));
});
/**
 * @param  list<string>  $command
 */
function runProcess(array $command, string $cwd): void
{
    $process = new Process($command, $cwd);
    $process->mustRun();
}
