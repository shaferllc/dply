<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy\ServerlessFlaskBuildTest;
use ZipArchive;

use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\DigitalOceanFunctionsArtifactBuilder;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it injects the flask adapter when building a flask app', function () {
    $root = storage_path('framework/testing/flask-build-'.uniqid());
    $origin = $root.'/origin';
    File::ensureDirectoryExists($origin);

    File::put($origin.'/requirements.txt', "Flask>=3.0\n");
    File::put($origin.'/app.py', "from flask import Flask\napp = Flask(__name__)\n\n@app.get('/')\ndef home():\n    return 'ok'\n");

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
        // No-op build so the test does not run a real `pip install`.
        'meta' => ['runtime_profile' => 'digitalocean_functions_web', 'serverless' => ['build_command' => 'true']],
    ]);

    try {
        $result = app(DigitalOceanFunctionsArtifactBuilder::class)->build($site);

        $zip = new ZipArchive;
        expect($zip->open($result['artifact_path']) === true)->toBeTrue();

        // The adapter takes the __main__.py Python entry; app.py stays put.
        $this->assertNotFalse($zip->locateName('__main__.py'));
        $this->assertNotFalse($zip->locateName('app.py'));

        $adapter = (string) $zip->getFromName('__main__.py');
        $this->assertStringContainsString('dplyMain', $adapter);
        $this->assertStringContainsString('"app.py"', $adapter);
        $this->assertStringContainsString('getattr(_module, "app")', $adapter);
        $zip->close();

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
