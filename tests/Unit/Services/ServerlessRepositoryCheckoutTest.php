<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerlessRepositoryCheckoutTest;
use App\Services\Deploy\ServerlessRepositoryCheckout;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
test('it falls back to the remote default branch when requested branch is missing', function () {
    $origin = storage_path('framework/testing/repository-checkout-origin-'.uniqid());
    $remote = storage_path('framework/testing/repository-checkout-remote-'.uniqid().'.git');

    File::ensureDirectoryExists($origin);

    (new Process(['git', 'init', '-b', 'master'], $origin))->mustRun();
    (new Process(['git', 'config', 'user.email', 'tests@example.com'], $origin))->mustRun();
    (new Process(['git', 'config', 'user.name', 'Tests'], $origin))->mustRun();
    File::put($origin.'/README.md', "# Demo\n");
    (new Process(['git', 'add', '.'], $origin))->mustRun();
    (new Process(['git', 'commit', '-m', 'Initial commit'], $origin))->mustRun();
    (new Process(['git', 'clone', '--bare', $origin, $remote]))->mustRun();

    $checkout = app(ServerlessRepositoryCheckout::class)->checkout(
        workspaceKey: 'checkout-test-'.uniqid(),
        repositoryUrl: $remote,
        branch: 'main',
    );

    expect($checkout['branch'])->toBe('master');
    expect($checkout['repository_path'].'/.git')->toBeDirectory();
    expect($checkout['working_directory'].'/README.md')->toBeFile();
    $this->assertStringContainsString('Falling back to remote default branch "master"', $checkout['output']);

    app(ServerlessRepositoryCheckout::class)->cleanup($checkout['workspace_path']);
    File::deleteDirectory($origin);
    File::deleteDirectory($remote);
});
