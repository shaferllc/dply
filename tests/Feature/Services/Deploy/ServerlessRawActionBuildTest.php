<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy\ServerlessRawActionBuildTest;
use ZipArchive;

use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\DigitalOceanFunctionsArtifactBuilder;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it injects the logging shim when building a raw node action', function () {
    $root = storage_path('framework/testing/raw-action-build-'.uniqid());
    $origin = $root.'/origin';
    File::ensureDirectoryExists($origin);

    // A bare OpenWhisk Node action — no framework, no package.json, no
    // build step. Without dply's shim this is invisible to the Logs page.
    File::put($origin.'/main.js', "exports.main = function (args) {\n  return { statusCode: 200, body: 'hello' };\n};\n");

    runProcess(['git', 'init', '-b', 'main'], $origin);
    runProcess(['git', 'config', 'user.email', 'tests@example.com'], $origin);
    runProcess(['git', 'config', 'user.name', 'Tests'], $origin);
    runProcess(['git', 'add', '.'], $origin);
    runProcess(['git', 'commit', '-m', 'Initial commit'], $origin);

    $server = Server::factory()->create([
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'git_repository_url' => $origin,
        'git_branch' => 'main',
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ]);

    try {
        $result = app(DigitalOceanFunctionsArtifactBuilder::class)->build($site);

        expect($result['artifact_path'])->toBeFile();

        $zip = new ZipArchive;
        expect($zip->open($result['artifact_path']) === true)->toBeTrue();

        // The shim takes the OpenWhisk Node entry slot; the user's
        // original action file is preserved alongside it.
        $this->assertNotFalse($zip->locateName('index.js'), 'shim should be packaged as index.js');
        $this->assertNotFalse($zip->locateName('main.js'), 'user action file should be preserved');

        $shim = (string) $zip->getFromName('index.js');
        $this->assertStringContainsString('dplyMain', $shim);
        $this->assertStringContainsString("require('./main.js')", $shim);
        $zip->close();

        // The deployer must point exec.main at the shim, not the user's main.
        expect($site->fresh()->serverlessConfig()['entrypoint'])->toBe('dplyMain');
    } finally {
        File::deleteDirectory($root);
        File::deleteDirectory(storage_path('app/serverless-artifacts/'.$site->id));
    }
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
