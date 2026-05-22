<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy\ServerlessGinBuildTest;
use ZipArchive;

use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\DigitalOceanFunctionsArtifactBuilder;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it injects the gin adapter when building a gin app', function () {
    $root = storage_path('framework/testing/gin-build-'.uniqid());
    $origin = $root.'/origin';
    File::ensureDirectoryExists($origin);

    File::put($origin.'/go.mod', "module example.com/api\n\ngo 1.22\n\nrequire github.com/gin-gonic/gin v1.10.0\n");
    File::put($origin.'/main.go', "package main\n\nimport (\n\t\"net/http\"\n\t\"github.com/gin-gonic/gin\"\n)\n\nfunc Router() http.Handler {\n\tr := gin.Default()\n\tr.GET(\"/\", func(c *gin.Context) { c.String(200, \"ok\") })\n\treturn r\n}\n\nfunc main() {}\n");

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
        'meta' => ['runtime_profile' => 'digitalocean_functions_web', 'serverless' => ['build_command' => 'true']],
    ]);

    try {
        $result = app(DigitalOceanFunctionsArtifactBuilder::class)->build($site);

        $zip = new ZipArchive;
        expect($zip->open($result['artifact_path']) === true)->toBeTrue();
        $this->assertNotFalse($zip->locateName('dply_adapter.go'));
        $this->assertNotFalse($zip->locateName('main.go'));

        $adapter = (string) $zip->getFromName('dply_adapter.go');
        $this->assertStringContainsString('func DplyMain(', $adapter);
        $zip->close();

        expect($site->fresh()->serverlessConfig()['entrypoint'])->toBe('DplyMain');
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
