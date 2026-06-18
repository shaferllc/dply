<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Deploy\ServerlessDjangoBuildTest;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Deploy\Services\DigitalOceanFunctionsArtifactBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ZipArchive;

uses(RefreshDatabase::class);

test('it injects the django adapter when building a django project', function () {
    $root = storage_path('framework/testing/django-build-'.uniqid());
    $origin = $root.'/origin';
    File::ensureDirectoryExists($origin.'/myproject');

    File::put($origin.'/requirements.txt', "Django>=5.0\n");
    File::put($origin.'/manage.py', "#!/usr/bin/env python\n");
    File::put($origin.'/myproject/__init__.py', '');
    File::put($origin.'/myproject/settings.py', "SECRET_KEY = 'x'\n");
    File::put($origin.'/myproject/wsgi.py', "import os\nfrom django.core.wsgi import get_wsgi_application\nos.environ.setdefault('DJANGO_SETTINGS_MODULE', 'myproject.settings')\napplication = get_wsgi_application()\n");

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
        $this->assertNotFalse($zip->locateName('__main__.py'));
        $this->assertNotFalse($zip->locateName('myproject/wsgi.py'));

        $adapter = (string) $zip->getFromName('__main__.py');
        $this->assertStringContainsString('dplyMain', $adapter);
        $this->assertStringContainsString('"myproject/wsgi.py"', $adapter);
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
