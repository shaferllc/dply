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

class ServerlessFlaskBuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_injects_the_flask_adapter_when_building_a_flask_app(): void
    {
        $root = storage_path('framework/testing/flask-build-'.uniqid());
        $origin = $root.'/origin';
        File::ensureDirectoryExists($origin);

        File::put($origin.'/requirements.txt', "Flask>=3.0\n");
        File::put($origin.'/app.py', "from flask import Flask\napp = Flask(__name__)\n\n@app.get('/')\ndef home():\n    return 'ok'\n");

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
            // No-op build so the test does not run a real `pip install`.
            'meta' => ['runtime_profile' => 'digitalocean_functions_web', 'serverless' => ['build_command' => 'true']],
        ]);

        try {
            $result = app(DigitalOceanFunctionsArtifactBuilder::class)->build($site);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($result['artifact_path']) === true);

            // The adapter takes the __main__.py Python entry; app.py stays put.
            $this->assertNotFalse($zip->locateName('__main__.py'));
            $this->assertNotFalse($zip->locateName('app.py'));

            $adapter = (string) $zip->getFromName('__main__.py');
            $this->assertStringContainsString('dplyMain', $adapter);
            $this->assertStringContainsString('"app.py"', $adapter);
            $this->assertStringContainsString('getattr(_module, "app")', $adapter);
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
