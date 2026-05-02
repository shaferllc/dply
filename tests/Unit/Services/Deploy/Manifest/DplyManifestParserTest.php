<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\Manifest;

use App\Services\Deploy\Manifest\DplyManifest;
use App\Services\Deploy\Manifest\DplyManifestException;
use App\Services\Deploy\Manifest\DplyManifestParser;
use PHPUnit\Framework\TestCase;

class DplyManifestParserTest extends TestCase
{
    private function parser(): DplyManifestParser
    {
        return new DplyManifestParser;
    }

    public function test_empty_string_yields_empty_manifest(): void
    {
        $manifest = $this->parser()->parseYaml('');

        $this->assertNull($manifest->runtime);
        $this->assertNull($manifest->version);
        $this->assertSame([], $manifest->build);
        $this->assertSame([], $manifest->release);
        $this->assertSame([], $manifest->processes);
        $this->assertSame([], $manifest->warnings);
    }

    public function test_whitespace_only_yields_empty_manifest(): void
    {
        $manifest = $this->parser()->parseYaml("\n   \n\t");

        $this->assertEquals(DplyManifest::empty(), $manifest);
    }

    public function test_yaml_explicit_null_document_yields_empty_manifest(): void
    {
        // Symfony YAML treats `~` and `null` as the explicit-null literal at the
        // top level, which our parser normalizes to an empty manifest.
        $manifest = $this->parser()->parseYaml('null');

        $this->assertEquals(DplyManifest::empty(), $manifest);
    }

    public function test_full_manifest_parses_all_fields(): void
    {
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

        $manifest = $this->parser()->parseYaml($yaml);

        $this->assertSame('php', $manifest->runtime);
        $this->assertSame('8.3', $manifest->version);
        $this->assertSame([
            'composer install --no-dev --optimize-autoloader',
            'php artisan optimize',
        ], $manifest->build);
        $this->assertSame(['php artisan migrate --force'], $manifest->release);

        $this->assertCount(2, $manifest->processes);
        $this->assertSame('worker', $manifest->processes['worker']->name);
        $this->assertSame('php artisan horizon', $manifest->processes['worker']->command);
        $this->assertSame(2, $manifest->processes['worker']->scale);
        $this->assertSame('scheduler', $manifest->processes['scheduler']->name);
        $this->assertSame('php artisan schedule:work', $manifest->processes['scheduler']->command);
        $this->assertSame(1, $manifest->processes['scheduler']->scale);
    }

    public function test_string_build_normalized_to_single_element_list(): void
    {
        $manifest = $this->parser()->parseYaml('build: composer install');

        $this->assertSame(['composer install'], $manifest->build);
    }

    public function test_string_release_normalized_to_single_element_list(): void
    {
        $manifest = $this->parser()->parseYaml('release: php artisan migrate');

        $this->assertSame(['php artisan migrate'], $manifest->release);
    }

    public function test_process_string_shorthand_treats_value_as_command_with_scale_one(): void
    {
        $yaml = <<<'YAML'
processes:
  worker: bundle exec sidekiq
YAML;

        $manifest = $this->parser()->parseYaml($yaml);

        $this->assertSame('bundle exec sidekiq', $manifest->processes['worker']->command);
        $this->assertSame(1, $manifest->processes['worker']->scale);
    }

    public function test_unquoted_numeric_version_is_coerced_to_string(): void
    {
        // YAML parses unquoted `22` as int and `8.3` as float — we coerce both
        // back to string so downstream code (mise / runtime detection) sees a
        // uniform shape regardless of how the user wrote the version.
        $intManifest = $this->parser()->parseYaml('version: 22');
        $this->assertSame('22', $intManifest->version);

        $floatManifest = $this->parser()->parseYaml('version: 8.3');
        $this->assertSame('8.3', $floatManifest->version);
    }

    public function test_runtime_is_lowercased(): void
    {
        $manifest = $this->parser()->parseYaml('runtime: PHP');

        $this->assertSame('php', $manifest->runtime);
    }

    public function test_unknown_top_level_keys_produce_warnings_not_errors(): void
    {
        $yaml = <<<'YAML'
runtime: node
version: "22"
domains:
  - example.com
custom_field: hello
YAML;

        $manifest = $this->parser()->parseYaml($yaml);

        $this->assertSame('node', $manifest->runtime);
        $this->assertCount(2, $manifest->warnings);
        $this->assertStringContainsString('domains', $manifest->warnings[0]);
        $this->assertStringContainsString('custom_field', $manifest->warnings[1]);
    }

    public function test_invalid_runtime_throws_with_field_path(): void
    {
        try {
            $this->parser()->parseYaml('runtime: cobol');
            $this->fail('Expected DplyManifestException');
        } catch (DplyManifestException $e) {
            $this->assertSame('runtime', $e->fieldPath);
            $this->assertStringContainsString('cobol', $e->getMessage());
        }
    }

    public function test_non_string_runtime_throws(): void
    {
        $this->expectException(DplyManifestException::class);

        $this->parser()->parseArray(['runtime' => 42]);
    }

    public function test_build_with_non_string_entry_throws_with_index_in_path(): void
    {
        try {
            $this->parser()->parseArray([
                'build' => ['composer install', 99],
            ]);
            $this->fail('Expected DplyManifestException');
        } catch (DplyManifestException $e) {
            $this->assertSame('build.1', $e->fieldPath);
        }
    }

    public function test_processes_as_list_throws(): void
    {
        try {
            $this->parser()->parseArray([
                'processes' => ['php artisan horizon'],
            ]);
            $this->fail('Expected DplyManifestException');
        } catch (DplyManifestException $e) {
            $this->assertSame('processes', $e->fieldPath);
        }
    }

    public function test_process_with_missing_command_throws(): void
    {
        try {
            $this->parser()->parseArray([
                'processes' => [
                    'worker' => ['scale' => 3],
                ],
            ]);
            $this->fail('Expected DplyManifestException');
        } catch (DplyManifestException $e) {
            $this->assertSame('processes.worker.command', $e->fieldPath);
        }
    }

    public function test_process_with_zero_scale_throws(): void
    {
        try {
            $this->parser()->parseArray([
                'processes' => [
                    'worker' => ['command' => 'sidekiq', 'scale' => 0],
                ],
            ]);
            $this->fail('Expected DplyManifestException');
        } catch (DplyManifestException $e) {
            $this->assertSame('processes.worker.scale', $e->fieldPath);
        }
    }

    public function test_process_with_non_integer_scale_throws(): void
    {
        try {
            $this->parser()->parseArray([
                'processes' => [
                    'worker' => ['command' => 'sidekiq', 'scale' => '3'],
                ],
            ]);
            $this->fail('Expected DplyManifestException');
        } catch (DplyManifestException $e) {
            $this->assertSame('processes.worker.scale', $e->fieldPath);
        }
    }

    public function test_top_level_list_throws(): void
    {
        $this->expectException(DplyManifestException::class);

        $this->parser()->parseYaml("- one\n- two");
    }

    public function test_invalid_yaml_throws_typed_exception(): void
    {
        try {
            $this->parser()->parseYaml("runtime: php\nversion: \"8.3");
            $this->fail('Expected DplyManifestException');
        } catch (DplyManifestException $e) {
            $this->assertNull($e->fieldPath);
            $this->assertStringContainsString('Invalid YAML', $e->getMessage());
        }
    }

    public function test_all_allowed_runtimes_parse_successfully(): void
    {
        foreach (DplyManifest::ALLOWED_RUNTIMES as $runtime) {
            $manifest = $this->parser()->parseYaml("runtime: {$runtime}");
            $this->assertSame($runtime, $manifest->runtime, "runtime `{$runtime}` should parse");
        }
    }

    public function test_empty_string_in_build_list_is_dropped(): void
    {
        $manifest = $this->parser()->parseArray([
            'build' => ['composer install', '', '   ', 'php artisan optimize'],
        ]);

        $this->assertSame(['composer install', 'php artisan optimize'], $manifest->build);
    }

    public function test_empty_runtime_string_yields_null(): void
    {
        $manifest = $this->parser()->parseArray(['runtime' => '   ']);

        $this->assertNull($manifest->runtime);
    }

    public function test_empty_version_string_yields_null(): void
    {
        $manifest = $this->parser()->parseArray(['version' => '']);

        $this->assertNull($manifest->version);
    }

    public function test_parse_file_throws_when_path_missing(): void
    {
        $this->expectException(DplyManifestException::class);

        $this->parser()->parseFile('/tmp/definitely-does-not-exist-'.uniqid().'.yaml');
    }

    public function test_parse_file_reads_real_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dply-manifest-test-');
        file_put_contents($path, "runtime: ruby\nversion: \"3.3\"\n");

        try {
            $manifest = $this->parser()->parseFile($path);
            $this->assertSame('ruby', $manifest->runtime);
            $this->assertSame('3.3', $manifest->version);
        } finally {
            @unlink($path);
        }
    }
}
