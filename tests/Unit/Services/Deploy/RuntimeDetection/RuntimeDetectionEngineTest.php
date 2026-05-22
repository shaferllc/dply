<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection;

use App\Services\Deploy\RuntimeDetection\GoRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\NodeRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\PhpRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\PythonRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\RubyRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\RuntimeDetection;
use App\Services\Deploy\RuntimeDetection\RuntimeDetectionEngine;
use App\Services\Deploy\RuntimeDetection\RuntimeDetector;
use App\Services\Deploy\RuntimeDetection\StaticRuntimeDetector;
use PHPUnit\Framework\TestCase;

class RuntimeDetectionEngineTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/dply-detection-engine-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function test_returns_empty_result_when_no_detector_matches(): void
    {
        $engine = $this->makeEngineWithRealDetectors();

        $result = $engine->detect($this->tempDir);

        $this->assertNull($result->best);
        $this->assertSame([], $result->all);
    }

    public function test_picks_the_only_non_null_detection(): void
    {
        file_put_contents($this->tempDir.'/go.mod', "module example.com/x\ngo 1.22\n");
        file_put_contents($this->tempDir.'/main.go', '');

        $result = $this->makeEngineWithRealDetectors()->detect($this->tempDir);

        $this->assertNotNull($result->best);
        $this->assertSame('go', $result->best->runtime);
        $this->assertCount(1, $result->all);
    }

    public function test_high_confidence_beats_medium_confidence(): void
    {
        // Laravel composer.json (PHP high) plus a plain index.html (Static medium).
        file_put_contents(
            $this->tempDir.'/composer.json',
            json_encode(['require' => ['laravel/framework' => '^11.0']]),
        );
        file_put_contents($this->tempDir.'/index.html', '<html></html>');

        $result = $this->makeEngineWithRealDetectors()->detect($this->tempDir);

        $this->assertNotNull($result->best);
        $this->assertSame('php', $result->best->runtime);
        $this->assertSame('laravel', $result->best->framework);
        $this->assertSame('high', $result->best->confidence);
        // The static detection is still surfaced in `all` for the UI panel.
        $this->assertCount(2, $result->all);
        $this->assertContains('static', array_map(fn ($d) => $d->runtime, $result->all));
    }

    public function test_php_wins_over_node_on_confidence_tie(): void
    {
        // Plain composer.json (PHP medium) + plain package.json (Node medium).
        file_put_contents($this->tempDir.'/composer.json', json_encode(['name' => 'me/app']));
        file_put_contents($this->tempDir.'/package.json', json_encode(['name' => 'tiny']));

        $result = $this->makeEngineWithRealDetectors()->detect($this->tempDir);

        $this->assertNotNull($result->best);
        $this->assertSame('php', $result->best->runtime);
    }

    public function test_node_wins_over_static_on_medium_tie_for_vite_shaped_repo(): void
    {
        // Vite-shape: package.json (no recognized framework, Node medium) +
        // index.html at root (Static medium). Node should win on tiebreaker.
        file_put_contents($this->tempDir.'/package.json', json_encode([
            'name' => 'spa',
            'devDependencies' => ['vite' => '^5.0'],
            'scripts' => ['build' => 'vite build', 'preview' => 'vite preview'],
        ]));
        file_put_contents($this->tempDir.'/index.html', '<div id="app"></div>');

        $result = $this->makeEngineWithRealDetectors()->detect($this->tempDir);

        $this->assertNotNull($result->best);
        $this->assertSame('node', $result->best->runtime);
        $this->assertCount(2, $result->all);
    }

    public function test_high_confidence_static_beats_medium_confidence_node(): void
    {
        // Astro-style repo where the user committed a build artifact and a
        // hugo.toml — contrived, but the rule is "high beats medium" regardless
        // of runtime priority.
        file_put_contents($this->tempDir.'/package.json', json_encode(['name' => 'tiny']));
        file_put_contents($this->tempDir.'/hugo.toml', "baseURL = \"https://x\"\n");

        $result = $this->makeEngineWithRealDetectors()->detect($this->tempDir);

        $this->assertNotNull($result->best);
        $this->assertSame('static', $result->best->runtime);
        $this->assertSame('hugo', $result->best->framework);
        $this->assertSame('high', $result->best->confidence);
    }

    public function test_jekyll_repo_with_gemfile_resolves_to_static(): void
    {
        // Jekyll: Gemfile (Ruby medium "ruby") + _config.yml (Static high "jekyll").
        // Static high wins on confidence — this is the right answer for Jekyll
        // since the deploy artifact is the built `_site/` dir, not a Ruby
        // long-running process.
        file_put_contents($this->tempDir.'/Gemfile', "source 'https://rubygems.org'\ngem 'jekyll'\n");
        file_put_contents($this->tempDir.'/_config.yml', "title: My Blog\n");

        $result = $this->makeEngineWithRealDetectors()->detect($this->tempDir);

        $this->assertNotNull($result->best);
        $this->assertSame('static', $result->best->runtime);
        $this->assertSame('jekyll', $result->best->framework);
    }

    public function test_returns_all_detections_in_order_they_fired(): void
    {
        file_put_contents($this->tempDir.'/composer.json', json_encode(['name' => 'me/app']));
        file_put_contents($this->tempDir.'/package.json', json_encode(['name' => 'tiny']));
        file_put_contents($this->tempDir.'/index.html', '<html></html>');

        $result = $this->makeEngineWithRealDetectors()->detect($this->tempDir);

        $runtimes = array_map(fn ($d) => $d->runtime, $result->all);
        $this->assertSame(['php', 'node', 'static'], $runtimes);
    }

    public function test_skips_detectors_that_return_null(): void
    {
        $alwaysNull = new class implements RuntimeDetector
        {
            public function runtime(): string
            {
                return 'never';
            }

            public function detect(string $workingDirectory): ?RuntimeDetection
            {
                return null;
            }
        };

        $alwaysHits = new class implements RuntimeDetector
        {
            public function runtime(): string
            {
                return 'fake';
            }

            public function detect(string $workingDirectory): RuntimeDetection
            {
                return new RuntimeDetection(
                    runtime: 'fake',
                    version: null,
                    framework: 'fake',
                    buildCommand: null,
                    startCommand: null,
                    appPort: null,
                    detectedFiles: [],
                    reasons: [],
                    processes: [],
                    confidence: 'low',
                );
            }
        };

        $engine = new RuntimeDetectionEngine([$alwaysNull, $alwaysHits, $alwaysNull]);

        $result = $engine->detect($this->tempDir);

        $this->assertNotNull($result->best);
        $this->assertSame('fake', $result->best->runtime);
        $this->assertCount(1, $result->all);
    }

    private function makeEngineWithRealDetectors(): RuntimeDetectionEngine
    {
        return new RuntimeDetectionEngine([
            new PhpRuntimeDetector,
            new NodeRuntimeDetector,
            new PythonRuntimeDetector,
            new RubyRuntimeDetector,
            new GoRuntimeDetector,
            new StaticRuntimeDetector,
        ]);
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
