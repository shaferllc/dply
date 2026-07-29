<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy\ServerlessExpressBuildTest;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Deploy\Services\DigitalOceanFunctionsArtifactBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ZipArchive;

uses(RefreshDatabase::class);

test('it injects the express adapter when building an express app', function () {
    $root = storage_path('framework/testing/express-build-'.uniqid());
    $origin = $root.'/origin';
    File::ensureDirectoryExists($origin);

    File::put($origin.'/package.json', json_encode([
        'name' => 'orders-api',
        'main' => 'index.js',
        'dependencies' => ['express' => '^4.19.0'],
    ], JSON_PRETTY_PRINT));
    File::put($origin.'/index.js', "const express = require('express');\nconst app = express();\napp.get('/', (req, res) => res.send('ok'));\nmodule.exports = app;\n");

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
        // No-op build so the test does not run a real `npm install`.
        'meta' => ['runtime_profile' => 'digitalocean_functions_web', 'serverless' => ['build_command' => 'true']],
    ]);

    try {
        $result = app(DigitalOceanFunctionsArtifactBuilder::class)->build($site);

        $zip = new ZipArchive;
        expect($zip->open($result['artifact_path']) === true)->toBeTrue();

        // The adapter takes index.js; the user's app is moved aside.
        $this->assertNotFalse($zip->locateName('index.js'));
        $this->assertNotFalse($zip->locateName('__dply_express_app.js'));

        $adapter = (string) $zip->getFromName('index.js');
        $this->assertStringContainsString('dplyMain', $adapter);
        $this->assertStringContainsString("require('./__dply_express_app.js')", $adapter);

        // serverless-http is wired into package.json.
        $package = json_decode((string) $zip->getFromName('package.json'), true);
        expect($package['dependencies'])->toHaveKey('serverless-http');
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
