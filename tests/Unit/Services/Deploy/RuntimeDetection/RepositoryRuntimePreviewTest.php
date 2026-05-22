<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection\RepositoryRuntimePreviewTest;

use App\Services\Deploy\Manifest\DplyManifestParser;
use App\Services\Deploy\RuntimeDetection\GitCloneException;
use App\Services\Deploy\RuntimeDetection\GitCloner;
use App\Services\Deploy\RuntimeDetection\GoRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\NodeRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\PhpRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\PythonRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\RepositoryRuntimePlanComposer;
use App\Services\Deploy\RuntimeDetection\RepositoryRuntimePreview;
use App\Services\Deploy\RuntimeDetection\RubyRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\RuntimeDetectionEngine;
use App\Services\Deploy\RuntimeDetection\StaticRuntimeDetector;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/dply-runtime-preview-'.uniqid();
    mkdir($this->tempDir);
});
afterEach(function () {
    removeDir($this->tempDir);
});
test('from path returns plan for recognized repo', function () {
    file_put_contents(
        $this->tempDir.'/composer.json',
        json_encode(['require' => ['laravel/framework' => '^11.0']]),
    );

    $plan = makePreview()->fromPath($this->tempDir);

    expect($plan)->not->toBeNull();
    expect($plan->runtime)->toBe('php');
    expect($plan->framework)->toBe('laravel');
});
test('from path returns null for unrecognized repo', function () {
    expect(makePreview()->fromPath($this->tempDir))->toBeNull();
});
test('from url clones then composes then cleans up', function () {
    // Fake cloner materializes a Node repo at the destination — no network.
    $fakeCloner = new class implements GitCloner
    {
        public function shallowClone(string $url, string $branch, string $destination): void
        {
            mkdir($destination, 0o755, true);
            file_put_contents(
                $destination.'/package.json',
                json_encode([
                    'name' => 'remote-app',
                    'dependencies' => ['next' => '^14.0'],
                    'scripts' => ['build' => 'next build', 'start' => 'next start'],
                ]),
            );
            $this->clonedTo = $destination;
        }
    };
    $preview = makePreview($fakeCloner);
    $plan = $preview->fromUrl('https://github.com/example/app.git', 'main');

    expect($plan)->not->toBeNull();
    expect($plan->runtime)->toBe('node');
    expect($plan->framework)->toBe('next');
    expect($fakeCloner->clonedTo)->not->toBeNull();

    // Tempdir was deleted after compose ran.
    $this->assertDirectoryDoesNotExist($fakeCloner->clonedTo);
});
test('from url normalizes blank branch to main', function () {
    $observed = ['branch' => null];
    $fakeCloner = new class($observed) implements GitCloner
    {
        public function __construct(private array &$observed) {}

        public function shallowClone(string $url, string $branch, string $destination): void
        {
            $this->observed['branch'] = $branch;
            mkdir($destination, 0o755, true);
        }
    };

    makePreview($fakeCloner)->fromUrl('https://example.com/x.git', '   ');

    expect($observed['branch'])->toBe('main');
});
test('from url cleans up even on clone failure', function () {
    $observed = ['cleanup_dirs' => []];
    $fakeCloner = new class($observed) implements GitCloner
    {
        public function __construct(private array &$observed) {}

        public function shallowClone(string $url, string $branch, string $destination): void
        {
            // Record the parent temp dir so the test can verify cleanup
            // happened (we can't observe the deletion directly without
            // racing it).
            $this->observed['cleanup_dirs'][] = dirname($destination);
            throw new GitCloneException('boom');
        }
    };

    $this->expectException(GitCloneException::class);

    try {
        makePreview($fakeCloner)->fromUrl('https://example.com/x.git', 'main');
    } finally {
        expect($observed['cleanup_dirs'])->not->toBeEmpty();
        foreach ($observed['cleanup_dirs'] as $dir) {
            $this->assertDirectoryDoesNotExist($dir);
        }
    }
});
test('from url returns null when cloned repo has no signals', function () {
    $fakeCloner = new class implements GitCloner
    {
        public function shallowClone(string $url, string $branch, string $destination): void
        {
            mkdir($destination, 0o755, true);
            // Empty repo — no manifest, no language signals.
        }
    };

    $plan = makePreview($fakeCloner)->fromUrl('https://example.com/x.git', 'main');

    expect($plan)->toBeNull();
});
function makePreview(?GitCloner $cloner = null): RepositoryRuntimePreview
{
    $cloner ??= new class implements GitCloner
    {
        public function shallowClone(string $url, string $branch, string $destination): void
        {
            throw new \LogicException('cloner not configured for this test');
        }
    };

    return new RepositoryRuntimePreview(
        new RepositoryRuntimePlanComposer(
            new RuntimeDetectionEngine([
                new PhpRuntimeDetector,
                new NodeRuntimeDetector,
                new PythonRuntimeDetector,
                new RubyRuntimeDetector,
                new GoRuntimeDetector,
                new StaticRuntimeDetector,
            ]),
            new DplyManifestParser,
        ),
        $cloner,
    );
}
function removeDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.'/'.$entry;
        is_dir($path) ? removeDir($path) : @unlink($path);
    }
    @rmdir($dir);
}
