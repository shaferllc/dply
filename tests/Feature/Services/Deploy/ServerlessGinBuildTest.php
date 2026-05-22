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

class ServerlessGinBuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_injects_the_gin_adapter_when_building_a_gin_app(): void
    {
        $root = storage_path('framework/testing/gin-build-'.uniqid());
        $origin = $root.'/origin';
        File::ensureDirectoryExists($origin);

        File::put($origin.'/go.mod', "module example.com/api\n\ngo 1.22\n\nrequire github.com/gin-gonic/gin v1.10.0\n");
        File::put($origin.'/main.go', "package main\n\nimport (\n\t\"net/http\"\n\t\"github.com/gin-gonic/gin\"\n)\n\nfunc Router() http.Handler {\n\tr := gin.Default()\n\tr.GET(\"/\", func(c *gin.Context) { c.String(200, \"ok\") })\n\treturn r\n}\n\nfunc main() {}\n");

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
            'meta' => ['runtime_profile' => 'digitalocean_functions_web', 'serverless' => ['build_command' => 'true']],
        ]);

        try {
            $result = app(DigitalOceanFunctionsArtifactBuilder::class)->build($site);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($result['artifact_path']) === true);
            $this->assertNotFalse($zip->locateName('dply_adapter.go'));
            $this->assertNotFalse($zip->locateName('main.go'));

            $adapter = (string) $zip->getFromName('dply_adapter.go');
            $this->assertStringContainsString('func DplyMain(', $adapter);
            $zip->close();

            $this->assertSame('DplyMain', $site->fresh()->serverlessConfig()['entrypoint']);
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
