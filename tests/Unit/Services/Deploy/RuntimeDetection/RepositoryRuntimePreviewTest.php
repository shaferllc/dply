<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection;

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
use PHPUnit\Framework\TestCase;

class RepositoryRuntimePreviewTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/dply-runtime-preview-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function test_from_path_returns_plan_for_recognized_repo(): void
    {
        file_put_contents(
            $this->tempDir.'/composer.json',
            json_encode(['require' => ['laravel/framework' => '^11.0']]),
        );

        $plan = $this->makePreview()->fromPath($this->tempDir);

        $this->assertNotNull($plan);
        $this->assertSame('php', $plan->runtime);
        $this->assertSame('laravel', $plan->framework);
    }

    public function test_from_path_returns_null_for_unrecognized_repo(): void
    {
        $this->assertNull($this->makePreview()->fromPath($this->tempDir));
    }

    public function test_from_url_clones_then_composes_then_cleans_up(): void
    {
        // Fake cloner materializes a Node repo at the destination — no network.
        $fakeCloner = new class implements GitCloner
        {
            public ?string $clonedTo = null;

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

        $preview = $this->makePreview($fakeCloner);
        $plan = $preview->fromUrl('https://github.com/example/app.git', 'main');

        $this->assertNotNull($plan);
        $this->assertSame('node', $plan->runtime);
        $this->assertSame('next', $plan->framework);
        $this->assertNotNull($fakeCloner->clonedTo);
        // Tempdir was deleted after compose ran.
        $this->assertDirectoryDoesNotExist($fakeCloner->clonedTo);
    }

    public function test_from_url_normalizes_blank_branch_to_main(): void
    {
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

        $this->makePreview($fakeCloner)->fromUrl('https://example.com/x.git', '   ');

        $this->assertSame('main', $observed['branch']);
    }

    public function test_from_url_cleans_up_even_on_clone_failure(): void
    {
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
            $this->makePreview($fakeCloner)->fromUrl('https://example.com/x.git', 'main');
        } finally {
            $this->assertNotEmpty($observed['cleanup_dirs']);
            foreach ($observed['cleanup_dirs'] as $dir) {
                $this->assertDirectoryDoesNotExist($dir);
            }
        }
    }

    public function test_from_url_returns_null_when_cloned_repo_has_no_signals(): void
    {
        $fakeCloner = new class implements GitCloner
        {
            public function shallowClone(string $url, string $branch, string $destination): void
            {
                mkdir($destination, 0o755, true);
                // Empty repo — no manifest, no language signals.
            }
        };

        $plan = $this->makePreview($fakeCloner)->fromUrl('https://example.com/x.git', 'main');

        $this->assertNull($plan);
    }

    private function makePreview(?GitCloner $cloner = null): RepositoryRuntimePreview
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

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
