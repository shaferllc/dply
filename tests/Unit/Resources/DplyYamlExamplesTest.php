<?php

declare(strict_types=1);

namespace Tests\Unit\Resources;

use App\Services\Deploy\Manifest\DplyManifestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DplyYamlExamplesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function exampleFiles(): array
    {
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
    }

    #[DataProvider('exampleFiles')]
    public function test_example_parses_cleanly(string $slug, string $path): void
    {
        $parser = new DplyManifestParser;
        $manifest = $parser->parseFile($path);

        $this->assertNotNull($manifest, "Example {$slug} should parse to a manifest.");
        $this->assertNotNull($manifest->runtime, "Example {$slug} should declare a runtime.");
    }

    #[DataProvider('exampleFiles')]
    public function test_example_runtime_is_in_the_canonical_set(string $slug, string $path): void
    {
        $manifest = (new DplyManifestParser)->parseFile($path);

        $this->assertContains(
            $manifest->runtime,
            ['php', 'node', 'python', 'ruby', 'go', 'static'],
            "Example {$slug} runtime ({$manifest->runtime}) is outside the canonical set.",
        );
    }

    #[DataProvider('exampleFiles')]
    public function test_example_processes_have_command_strings(string $slug, string $path): void
    {
        $manifest = (new DplyManifestParser)->parseFile($path);

        foreach ($manifest->processes as $name => $process) {
            $this->assertNotEmpty(
                $process->command,
                "Example {$slug}: process '{$name}' must have a command.",
            );
        }
    }
}
