<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\Manifest\DplyManifestParserTest;

use App\Services\Deploy\Manifest\DplyManifest;
use App\Services\Deploy\Manifest\DplyManifestException;
use App\Services\Deploy\Manifest\DplyManifestParser;

function parser(): DplyManifestParser
{
    return new DplyManifestParser;
}
test('empty string yields empty manifest', function () {
    $manifest = parser()->parseYaml('');

    expect($manifest->runtime)->toBeNull();
    expect($manifest->version)->toBeNull();
    expect($manifest->build)->toBe([]);
    expect($manifest->release)->toBe([]);
    expect($manifest->processes)->toBe([]);
    expect($manifest->warnings)->toBe([]);
});
test('whitespace only yields empty manifest', function () {
    $manifest = parser()->parseYaml("\n   \n\t");

    expect($manifest)->toEqual(DplyManifest::empty());
});
test('yaml explicit null document yields empty manifest', function () {
    // Symfony YAML treats `~` and `null` as the explicit-null literal at the
    // top level, which our parser normalizes to an empty manifest.
    $manifest = parser()->parseYaml('null');

    expect($manifest)->toEqual(DplyManifest::empty());
});
test('full manifest parses all fields', function () {
    $yaml = <<<'YAML'
runtime: php
version: "8.3"
build:
  - composer install --no-dev --optimize-autoloader
  - php artisan optimize
release:
  - php artisan migrate --force
processes:
  worker:
    command: php artisan horizon
    scale: 2
  scheduler:
    command: php artisan schedule:work
YAML;

    $manifest = parser()->parseYaml($yaml);

    expect($manifest->runtime)->toBe('php');
    expect($manifest->version)->toBe('8.3');
    expect($manifest->build)->toBe([
        'composer install --no-dev --optimize-autoloader',
        'php artisan optimize',
    ]);
    expect($manifest->release)->toBe(['php artisan migrate --force']);

    expect($manifest->processes)->toHaveCount(2);
    expect($manifest->processes['worker']->name)->toBe('worker');
    expect($manifest->processes['worker']->command)->toBe('php artisan horizon');
    expect($manifest->processes['worker']->scale)->toBe(2);
    expect($manifest->processes['scheduler']->name)->toBe('scheduler');
    expect($manifest->processes['scheduler']->command)->toBe('php artisan schedule:work');
    expect($manifest->processes['scheduler']->scale)->toBe(1);
});
test('string build normalized to single element list', function () {
    $manifest = parser()->parseYaml('build: composer install');

    expect($manifest->build)->toBe(['composer install']);
});
test('string release normalized to single element list', function () {
    $manifest = parser()->parseYaml('release: php artisan migrate');

    expect($manifest->release)->toBe(['php artisan migrate']);
});
test('process string shorthand treats value as command with scale one', function () {
    $yaml = <<<'YAML'
processes:
  worker: bundle exec sidekiq
YAML;

    $manifest = parser()->parseYaml($yaml);

    expect($manifest->processes['worker']->command)->toBe('bundle exec sidekiq');
    expect($manifest->processes['worker']->scale)->toBe(1);
});
test('unquoted numeric version is coerced to string', function () {
    // YAML parses unquoted `22` as int and `8.3` as float — we coerce both
    // back to string so downstream code (mise / runtime detection) sees a
    // uniform shape regardless of how the user wrote the version.
    $intManifest = parser()->parseYaml('version: 22');
    expect($intManifest->version)->toBe('22');

    $floatManifest = parser()->parseYaml('version: 8.3');
    expect($floatManifest->version)->toBe('8.3');
});
test('runtime is lowercased', function () {
    $manifest = parser()->parseYaml('runtime: PHP');

    expect($manifest->runtime)->toBe('php');
});
test('unknown top level keys produce warnings not errors', function () {
    $yaml = <<<'YAML'
runtime: node
version: "22"
domains:
  - example.com
custom_field: hello
YAML;

    $manifest = parser()->parseYaml($yaml);

    expect($manifest->runtime)->toBe('node');
    expect($manifest->warnings)->toHaveCount(2);
    $this->assertStringContainsString('domains', $manifest->warnings[0]);
    $this->assertStringContainsString('custom_field', $manifest->warnings[1]);
});
test('invalid runtime throws with field path', function () {
    try {
        parser()->parseYaml('runtime: cobol');
        $this->fail('Expected DplyManifestException');
    } catch (DplyManifestException $e) {
        expect($e->fieldPath)->toBe('runtime');
        $this->assertStringContainsString('cobol', $e->getMessage());
    }
});
test('non string runtime throws', function () {
    $this->expectException(DplyManifestException::class);

    parser()->parseArray(['runtime' => 42]);
});
test('build with non string entry throws with index in path', function () {
    try {
        parser()->parseArray([
            'build' => ['composer install', 99],
        ]);
        $this->fail('Expected DplyManifestException');
    } catch (DplyManifestException $e) {
        expect($e->fieldPath)->toBe('build.1');
    }
});
test('processes as list throws', function () {
    try {
        parser()->parseArray([
            'processes' => ['php artisan horizon'],
        ]);
        $this->fail('Expected DplyManifestException');
    } catch (DplyManifestException $e) {
        expect($e->fieldPath)->toBe('processes');
    }
});
test('process with missing command throws', function () {
    try {
        parser()->parseArray([
            'processes' => [
                'worker' => ['scale' => 3],
            ],
        ]);
        $this->fail('Expected DplyManifestException');
    } catch (DplyManifestException $e) {
        expect($e->fieldPath)->toBe('processes.worker.command');
    }
});
test('process with zero scale throws', function () {
    try {
        parser()->parseArray([
            'processes' => [
                'worker' => ['command' => 'sidekiq', 'scale' => 0],
            ],
        ]);
        $this->fail('Expected DplyManifestException');
    } catch (DplyManifestException $e) {
        expect($e->fieldPath)->toBe('processes.worker.scale');
    }
});
test('process with non integer scale throws', function () {
    try {
        parser()->parseArray([
            'processes' => [
                'worker' => ['command' => 'sidekiq', 'scale' => '3'],
            ],
        ]);
        $this->fail('Expected DplyManifestException');
    } catch (DplyManifestException $e) {
        expect($e->fieldPath)->toBe('processes.worker.scale');
    }
});
test('top level list throws', function () {
    $this->expectException(DplyManifestException::class);

    parser()->parseYaml("- one\n- two");
});
test('invalid yaml throws typed exception', function () {
    try {
        parser()->parseYaml("runtime: php\nversion: \"8.3");
        $this->fail('Expected DplyManifestException');
    } catch (DplyManifestException $e) {
        expect($e->fieldPath)->toBeNull();
        $this->assertStringContainsString('Invalid YAML', $e->getMessage());
    }
});
test('all allowed runtimes parse successfully', function () {
    foreach (DplyManifest::ALLOWED_RUNTIMES as $runtime) {
        $manifest = parser()->parseYaml("runtime: {$runtime}");
        expect($manifest->runtime)->toBe($runtime, "runtime `{$runtime}` should parse");
    }
});
test('empty string in build list is dropped', function () {
    $manifest = parser()->parseArray([
        'build' => ['composer install', '', '   ', 'php artisan optimize'],
    ]);

    expect($manifest->build)->toBe(['composer install', 'php artisan optimize']);
});
test('empty runtime string yields null', function () {
    $manifest = parser()->parseArray(['runtime' => '   ']);

    expect($manifest->runtime)->toBeNull();
});
test('empty version string yields null', function () {
    $manifest = parser()->parseArray(['version' => '']);

    expect($manifest->version)->toBeNull();
});
test('parse file throws when path missing', function () {
    $this->expectException(DplyManifestException::class);

    parser()->parseFile('/tmp/definitely-does-not-exist-'.uniqid().'.yaml');
});
test('parse file reads real file', function () {
    $path = tempnam(sys_get_temp_dir(), 'dply-manifest-test-');
    file_put_contents($path, "runtime: ruby\nversion: \"3.3\"\n");

    try {
        $manifest = parser()->parseFile($path);
        expect($manifest->runtime)->toBe('ruby');
        expect($manifest->version)->toBe('3.3');
    } finally {
        @unlink($path);
    }
});
