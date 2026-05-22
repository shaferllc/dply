<?php

declare(strict_types=1);

namespace Tests\Unit\Resources\DplyYamlExamplesTest;
use App\Services\Deploy\Manifest\DplyManifestParser;
use PHPUnit\Framework\Attributes\DataProvider;
/**
 * @return array<string, array{0: string, 1: string}>
 */
dataset('exampleFiles', function () {
    $dir = dirname(__DIR__, 3).'/resources/dply-yaml-examples';
    $cases = [];
    foreach ((scandir($dir) ?: []) as $entry) {
        if (! str_ends_with($entry, '.yaml')) {
            continue;
        }
        $slug = str_replace('.yaml', '', $entry);
        $cases[$slug] = [$slug, $dir.'/'.$entry];
    }

    return $cases;
});
test('example parses cleanly', function (string $slug, string $path) {
    $parser = new DplyManifestParser;
    $manifest = $parser->parseFile($path);

    expect($manifest)->not->toBeNull("Example {$slug} should parse to a manifest.");
    expect($manifest->runtime)->not->toBeNull("Example {$slug} should declare a runtime.");
})->with('exampleFiles');
test('example runtime is in the canonical set', function (string $slug, string $path) {
    $manifest = (new DplyManifestParser)->parseFile($path);

    expect(['php', 'node', 'python', 'ruby', 'go', 'static'])->toContain($manifest->runtime, "Example {$slug} runtime ({$manifest->runtime}) is outside the canonical set.");
})->with('exampleFiles');
test('example processes have command strings', function (string $slug, string $path) {
    $manifest = (new DplyManifestParser)->parseFile($path);

    foreach ($manifest->processes as $name => $process) {
        expect($process->command)->not->toBeEmpty("Example {$slug}: process '{$name}' must have a command.");
    }
})->with('exampleFiles');
