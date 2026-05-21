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

class DigitalOceanFunctionsLaravelBuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_injects_the_laravel_adapter_into_a_digitalocean_functions_build(): void
    {
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

        $this->runProcess(['git', 'init', '-b', 'main'], $origin);
        $this->runProcess(['git', 'config', 'user.email', 'tests@example.com'], $origin);
        $this->runProcess(['git', 'config', 'user.name', 'Tests'], $origin);
        $this->runProcess(['git', 'add', '.'], $origin);
        $this->runProcess(['git', 'commit', '-m', 'init'], $origin);

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

        $this->assertFileExists($result['artifact_path']);
        $this->assertStringContainsString('Injected DigitalOcean Functions Laravel adapter', $result['output']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['artifact_path']) === true);
        $this->assertNotFalse($zip->locateName('index.php'), 'adapter index.php should be in the artifact');
        $handler = (string) $zip->getFromName('index.php');
        $this->assertNotFalse($zip->locateName('.env'), 'managed .env should be in the artifact');
        $env = (string) $zip->getFromName('.env');
        $this->assertNotFalse($zip->locateName('deploy-marker.txt'), 'the deploy command should have run');
        $this->assertNotFalse($zip->locateName('composer.json'), 'app files should still be packaged');
        // Artifact slimming — VCS metadata and .dplyignore entries are dropped.
        $this->assertFalse($zip->locateName('.git/HEAD'), '.git must not be in the artifact');
        $this->assertFalse($zip->locateName('secret-notes.txt'), '.dplyignore entries must be excluded');
        $zip->close();
        $this->assertStringContainsString('function main(array $args)', $handler);

        // A Laravel function is given a stable, managed APP_KEY.
        $this->assertMatchesRegularExpression('/APP_KEY=base64:.+/', $env);
        $this->assertMatchesRegularExpression('/APP_KEY=base64:.+/', (string) $site->fresh()->env_file_content);

        // The deployer reads entrypoint as OpenWhisk `exec.main`.
        $this->assertSame('main', $site->fresh()->serverlessResolvedConfig()['entrypoint']);

        File::deleteDirectory($root);
        File::deleteDirectory(storage_path('app/serverless-artifacts/'.$site->id));
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->mustRun();
    }
}
