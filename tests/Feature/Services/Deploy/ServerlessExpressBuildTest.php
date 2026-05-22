<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy;

use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\DigitalOceanFunctionsArtifactBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class ServerlessExpressBuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_injects_the_express_adapter_when_building_an_express_app(): void
    {
        $root = storage_path('framework/testing/express-build-'.uniqid());
        $origin = $root.'/origin';
        File::ensureDirectoryExists($origin);

        File::put($origin.'/package.json', json_encode([
            'name' => 'orders-api',
            'main' => 'index.js',
            'dependencies' => ['express' => '^4.19.0'],
        ], JSON_PRETTY_PRINT));
        File::put($origin.'/index.js', "const express = require('express');\nconst app = express();\napp.get('/', (req, res) => res.send('ok'));\nmodule.exports = app;\n");

        $this->runProcess(['git', 'init', '-b', 'main'], $origin);
        $this->runProcess(['git', 'config', 'user.email', 'tests@example.com'], $origin);
        $this->runProcess(['git', 'config', 'user.name', 'Tests'], $origin);
        $this->runProcess(['git', 'add', '.'], $origin);
        $this->runProcess(['git', 'commit', '-m', 'Initial commit'], $origin);

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
            $this->assertTrue($zip->open($result['artifact_path']) === true);

            // The adapter takes index.js; the user's app is moved aside.
            $this->assertNotFalse($zip->locateName('index.js'));
            $this->assertNotFalse($zip->locateName('__dply_express_app.js'));

            $adapter = (string) $zip->getFromName('index.js');
            $this->assertStringContainsString('dplyMain', $adapter);
            $this->assertStringContainsString("require('./__dply_express_app.js')", $adapter);

            // serverless-http is wired into package.json.
            $package = json_decode((string) $zip->getFromName('package.json'), true);
            $this->assertArrayHasKey('serverless-http', $package['dependencies']);
            $zip->close();

            $this->assertSame('dplyMain', $site->fresh()->serverlessConfig()['entrypoint']);
        } finally {
            File::deleteDirectory($root);
            File::deleteDirectory(storage_path('app/serverless-artifacts/'.$site->id));
        }
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
