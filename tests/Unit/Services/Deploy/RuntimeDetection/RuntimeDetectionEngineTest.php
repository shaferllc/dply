<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection\RuntimeDetectionEngineTest;
use App\Services\Deploy\RuntimeDetection\GoRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\NodeRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\PhpRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\PythonRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\RubyRuntimeDetector;
use App\Services\Deploy\RuntimeDetection\RuntimeDetection;
use App\Services\Deploy\RuntimeDetection\RuntimeDetectionEngine;
use App\Services\Deploy\RuntimeDetection\RuntimeDetector;
use App\Services\Deploy\RuntimeDetection\StaticRuntimeDetector;
beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/dply-detection-engine-'.uniqid();
    mkdir($this->tempDir);
});
afterEach(function () {
    removeDir($this->tempDir);
});
test('returns empty result when no detector matches', function () {
    $engine = makeEngineWithRealDetectors();

    $result = $engine->detect($this->tempDir);

    expect($result->best)->toBeNull();
    expect($result->all)->toBe([]);
});
test('picks the only non null detection', function () {
    file_put_contents($this->tempDir.'/go.mod', "module example.com/x\ngo 1.22\n");
    file_put_contents($this->tempDir.'/main.go', '');

    $result = makeEngineWithRealDetectors()->detect($this->tempDir);

    expect($result->best)->not->toBeNull();
    expect($result->best->runtime)->toBe('go');
    expect($result->all)->toHaveCount(1);
});
test('high confidence beats medium confidence', function () {
    // Laravel composer.json (PHP high) plus a plain index.html (Static medium).
    file_put_contents(
        $this->tempDir.'/composer.json',
        json_encode(['require' => ['laravel/framework' => '^11.0']]),
    );
    file_put_contents($this->tempDir.'/index.html', '<html></html>');

    $result = makeEngineWithRealDetectors()->detect($this->tempDir);

    expect($result->best)->not->toBeNull();
    expect($result->best->runtime)->toBe('php');
    expect($result->best->framework)->toBe('laravel');
    expect($result->best->confidence)->toBe('high');

    // The static detection is still surfaced in `all` for the UI panel.
    expect($result->all)->toHaveCount(2);
    expect(array_map(fn ($d) => $d->runtime, $result->all))->toContain('static');
});
test('php wins over node on confidence tie', function () {
    // Plain composer.json (PHP medium) + plain package.json (Node medium).
    file_put_contents($this->tempDir.'/composer.json', json_encode(['name' => 'me/app']));
    file_put_contents($this->tempDir.'/package.json', json_encode(['name' => 'tiny']));

    $result = makeEngineWithRealDetectors()->detect($this->tempDir);

    expect($result->best)->not->toBeNull();
    expect($result->best->runtime)->toBe('php');
});
test('node wins over static on medium tie for vite shaped repo', function () {
    // Vite-shape: package.json (no recognized framework, Node medium) +
    // index.html at root (Static medium). Node should win on tiebreaker.
    file_put_contents($this->tempDir.'/package.json', json_encode([
        'name' => 'spa',
        'devDependencies' => ['vite' => '^5.0'],
        'scripts' => ['build' => 'vite build', 'preview' => 'vite preview'],
    ]));
    file_put_contents($this->tempDir.'/index.html', '<div id="app"></div>');

    $result = makeEngineWithRealDetectors()->detect($this->tempDir);

    expect($result->best)->not->toBeNull();
    expect($result->best->runtime)->toBe('node');
    expect($result->all)->toHaveCount(2);
});
test('high confidence static beats medium confidence node', function () {
    // Astro-style repo where the user committed a build artifact and a
    // hugo.toml — contrived, but the rule is "high beats medium" regardless
    // of runtime priority.
    file_put_contents($this->tempDir.'/package.json', json_encode(['name' => 'tiny']));
    file_put_contents($this->tempDir.'/hugo.toml', "baseURL = \"https://x\"\n");

    $result = makeEngineWithRealDetectors()->detect($this->tempDir);

    expect($result->best)->not->toBeNull();
    expect($result->best->runtime)->toBe('static');
    expect($result->best->framework)->toBe('hugo');
    expect($result->best->confidence)->toBe('high');
});
test('jekyll repo with gemfile resolves to static', function () {
    // Jekyll: Gemfile (Ruby medium "ruby") + _config.yml (Static high "jekyll").
    // Static high wins on confidence — this is the right answer for Jekyll
    // since the deploy artifact is the built `_site/` dir, not a Ruby
    // long-running process.
    file_put_contents($this->tempDir.'/Gemfile', "source 'https://rubygems.org'\ngem 'jekyll'\n");
    file_put_contents($this->tempDir.'/_config.yml', "title: My Blog\n");

    $result = makeEngineWithRealDetectors()->detect($this->tempDir);

    expect($result->best)->not->toBeNull();
    expect($result->best->runtime)->toBe('static');
    expect($result->best->framework)->toBe('jekyll');
});
test('returns all detections in order they fired', function () {
    file_put_contents($this->tempDir.'/composer.json', json_encode(['name' => 'me/app']));
    file_put_contents($this->tempDir.'/package.json', json_encode(['name' => 'tiny']));
    file_put_contents($this->tempDir.'/index.html', '<html></html>');

    $result = makeEngineWithRealDetectors()->detect($this->tempDir);

    $runtimes = array_map(fn ($d) => $d->runtime, $result->all);
    expect($runtimes)->toBe(['php', 'node', 'static']);
});
test('skips detectors that return null', function () {
    $alwaysNull = new class implements RuntimeDetector
    {
        function runtime(): string
        {
            return 'never';
        }

        function detect(string $workingDirectory): ?RuntimeDetection
        {
            return null;
        }
    };

    $alwaysHits = new class implements RuntimeDetector
    {
        function runtime(): string
        {
            return 'fake';
        }

        function detect(string $workingDirectory): RuntimeDetection
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

    expect($result->best)->not->toBeNull();
    expect($result->best->runtime)->toBe('fake');
    expect($result->all)->toHaveCount(1);
});
function makeEngineWithRealDetectors(): RuntimeDetectionEngine
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
