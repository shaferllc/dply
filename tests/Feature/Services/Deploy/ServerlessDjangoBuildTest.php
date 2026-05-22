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

class ServerlessDjangoBuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_injects_the_django_adapter_when_building_a_django_project(): void
    {
        $root = storage_path('framework/testing/django-build-'.uniqid());
        $origin = $root.'/origin';
        File::ensureDirectoryExists($origin.'/myproject');

        File::put($origin.'/requirements.txt', "Django>=5.0\n");
        File::put($origin.'/manage.py', "#!/usr/bin/env python\n");
        File::put($origin.'/myproject/__init__.py', '');
        File::put($origin.'/myproject/settings.py', "SECRET_KEY = 'x'\n");
        File::put($origin.'/myproject/wsgi.py', "import os\nfrom django.core.wsgi import get_wsgi_application\nos.environ.setdefault('DJANGO_SETTINGS_MODULE', 'myproject.settings')\napplication = get_wsgi_application()\n");

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
            $this->assertNotFalse($zip->locateName('__main__.py'));
            $this->assertNotFalse($zip->locateName('myproject/wsgi.py'));

            $adapter = (string) $zip->getFromName('__main__.py');
            $this->assertStringContainsString('dplyMain', $adapter);
            $this->assertStringContainsString('"myproject/wsgi.py"', $adapter);
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
