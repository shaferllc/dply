<?php

namespace Tests\Unit\Services;

use App\Services\Deploy\ServerlessRuntimeDetector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ServerlessRuntimeDetectorTest extends TestCase
{
    public function test_ruby_repo_falls_through_to_unknown_since_do_functions_has_no_ruby_runtime(): void
    {
        // DigitalOcean Functions (managed OpenWhisk) ships no Ruby runtime,
        // so a Rails repo must not claim a `rails` framework it can never
        // deploy — it falls through to `unknown` like any unrecognized repo.
        $dir = storage_path('framework/testing/serverless-detector-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        File::makeDirectory($dir.'/config', 0755, true);
        File::put($dir.'/Gemfile', "source 'https://rubygems.org'\n");
        File::put($dir.'/config/application.rb', "module App\nend\n");

        try {
            $detector = new ServerlessRuntimeDetector;
            $result = $detector->detect($dir, [
                'supports_php_runtime' => true,
                'supports_node_runtime' => true,
                'default_runtime' => 'nodejs:18',
                'default_entrypoint' => 'main',
                'default_package' => 'default',
            ]);

            $this->assertSame('unknown', $result['framework']);
            $this->assertSame('unknown', $result['language']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_detects_symfony_via_framework_bundle(): void
    {
        $dir = storage_path('framework/testing/serverless-detector-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        File::put($dir.'/composer.json', json_encode([
            'require' => [
                'symfony/framework-bundle' => '^7.0',
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $detector = new ServerlessRuntimeDetector;
            $result = $detector->detect($dir, [
                'supports_php_runtime' => true,
                'supports_node_runtime' => true,
                'default_runtime' => 'php:8.3',
                'default_entrypoint' => 'public/index.php',
                'default_package' => 'default',
            ]);

            $this->assertSame('symfony', $result['framework']);
            $this->assertSame('php', $result['language']);
            $this->assertSame('medium', $result['confidence']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_detects_django_with_manage_py(): void
    {
        $dir = storage_path('framework/testing/serverless-detector-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        File::put($dir.'/manage.py', "# Django\n");
        File::put($dir.'/requirements.txt', "Django>=4.2\n");

        try {
            $detector = new ServerlessRuntimeDetector;
            $result = $detector->detect($dir, [
                'supports_php_runtime' => true,
                'supports_node_runtime' => true,
                'supports_python_runtime' => true,
                'default_python_runtime' => 'python3.12',
                'default_runtime' => 'nodejs:20',
                'default_entrypoint' => 'index',
                'default_package' => 'default',
            ]);

            $this->assertSame('django', $result['framework']);
            $this->assertSame('python', $result['language']);
            $this->assertSame('high', $result['confidence']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_detects_fastapi_from_requirements(): void
    {
        $dir = storage_path('framework/testing/serverless-detector-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        File::put($dir.'/requirements.txt', "fastapi>=0.100\nuvicorn[standard]\n");

        try {
            $detector = new ServerlessRuntimeDetector;
            $result = $detector->detect($dir, [
                'supports_php_runtime' => true,
                'supports_node_runtime' => true,
                'supports_python_runtime' => true,
                'default_python_runtime' => 'python3.12',
                'default_runtime' => 'nodejs:20',
                'default_entrypoint' => 'index',
                'default_package' => 'default',
            ]);

            $this->assertSame('fastapi', $result['framework']);
            $this->assertSame('python', $result['language']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_detects_laravel_octane_flag_from_composer(): void
    {
        $dir = storage_path('framework/testing/serverless-detector-laravel-octane-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        File::makeDirectory($dir.'/bootstrap', 0755, true);
        File::makeDirectory($dir.'/routes', 0755, true);
        File::makeDirectory($dir.'/public', 0755, true);
        File::put($dir.'/artisan', "#!/usr/bin/env php\n");
        File::put($dir.'/bootstrap/app.php', "<?php\n");
        File::put($dir.'/routes/web.php', "<?php\n");
        File::put($dir.'/public/index.php', "<?php\n");
        File::put($dir.'/composer.json', json_encode([
            'require' => [
                'laravel/framework' => '^12.0',
                'laravel/octane' => '^2.0',
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $detector = new ServerlessRuntimeDetector;
            $result = $detector->detect($dir, [
                'supports_php_runtime' => true,
                'supports_node_runtime' => true,
                'default_runtime' => 'php:8.3',
                'default_entrypoint' => 'public/index.php',
                'default_package' => 'default',
            ]);

            $this->assertSame('laravel', $result['framework']);
            $this->assertTrue($result['laravel_octane']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** Capabilities advertising all four DigitalOcean Functions runtimes. */
    private function doCapabilities(array $overrides = []): array
    {
        return array_merge([
            'supports_php_runtime' => true,
            'supports_node_runtime' => true,
            'supports_python_runtime' => true,
            'supports_go_runtime' => true,
            'default_runtime' => 'nodejs:18',
            'default_python_runtime' => 'python:3.11',
            'default_entrypoint' => 'main',
            'default_package' => 'default',
        ], $overrides);
    }

    /**
     * Detect a repo built from a map of relative path => file contents.
     */
    private function detectRepo(array $files, array $capabilityOverrides = []): array
    {
        $dir = storage_path('framework/testing/serverless-detector-'.uniqid());
        File::makeDirectory($dir, 0755, true);

        try {
            foreach ($files as $relative => $contents) {
                $path = $dir.'/'.$relative;
                File::ensureDirectoryExists(dirname($path));
                File::put($path, $contents);
            }

            return (new ServerlessRuntimeDetector)->detect($dir, $this->doCapabilities($capabilityOverrides));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_detects_a_raw_node_action_from_main_js(): void
    {
        $result = $this->detectRepo(['main.js' => "exports.main = function (args) {\n  return { body: 'ok' };\n};\n"]);

        $this->assertSame('raw', $result['framework']);
        $this->assertSame('raw', $result['deploy_kind']);
        $this->assertSame('node', $result['language']);
        $this->assertSame('main.js', $result['entry_file']);
        $this->assertSame('main', $result['entrypoint']);
        $this->assertSame('nodejs:18', $result['runtime']);
        $this->assertFalse($result['unsupported_for_target']);
    }

    public function test_detects_a_raw_python_action_from_main_py(): void
    {
        $result = $this->detectRepo(['main.py' => "def main(args):\n    return {'body': 'ok'}\n"]);

        $this->assertSame('raw', $result['framework']);
        $this->assertSame('python', $result['language']);
        $this->assertSame('main.py', $result['entry_file']);
        $this->assertSame('python:3.11', $result['runtime']);
    }

    public function test_detects_a_raw_php_action_from_main_php(): void
    {
        $result = $this->detectRepo(['main.php' => "<?php\nfunction main(array \$args): array\n{\n    return ['body' => 'ok'];\n}\n"]);

        $this->assertSame('raw', $result['framework']);
        $this->assertSame('php', $result['language']);
        $this->assertSame('main.php', $result['entry_file']);
        $this->assertSame('php:8.3', $result['runtime']);
    }

    public function test_detects_a_raw_go_action_from_main_go(): void
    {
        $result = $this->detectRepo(['main.go' => "package main\n\nfunc Main(args map[string]interface{}) map[string]interface{} {\n  return args\n}\n"]);

        $this->assertSame('raw', $result['framework']);
        $this->assertSame('go', $result['language']);
        $this->assertSame('main.go', $result['entry_file']);
        $this->assertSame('go:1.22', $result['runtime']);
    }

    public function test_a_file_without_a_main_symbol_is_not_a_raw_action(): void
    {
        $result = $this->detectRepo(['main.js' => "console.log('not an action');\n"]);

        $this->assertSame('unknown', $result['framework']);
        $this->assertSame('unknown', $result['deploy_kind']);
    }

    public function test_detects_an_openwhisk_project_yml_as_a_raw_multi_action_package(): void
    {
        $result = $this->detectRepo(['project.yml' => "packages:\n  default:\n    actions:\n      hello:\n        function: hello.js\n"]);

        $this->assertSame('raw', $result['framework']);
        $this->assertSame('raw', $result['deploy_kind']);
        $this->assertSame('mixed', $result['language']);
        $this->assertSame('high', $result['confidence']);
    }

    public function test_framework_markers_win_over_a_raw_main_file(): void
    {
        // A Laravel repo that also happens to carry a root main.php must
        // still be classified as the framework — ladder step 1 beats step 4.
        $result = $this->detectRepo([
            'artisan' => "#!/usr/bin/env php\n",
            'bootstrap/app.php' => "<?php\n",
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^12.0']]),
            'main.php' => "<?php\nfunction main(\$args) { return \$args; }\n",
        ]);

        $this->assertSame('laravel', $result['framework']);
        $this->assertSame('framework', $result['deploy_kind']);
    }

    public function test_a_raw_action_is_unsupported_when_the_target_lacks_that_runtime(): void
    {
        $result = $this->detectRepo(
            ['main.go' => "package main\nfunc Main(a map[string]interface{}) map[string]interface{} { return a }\n"],
            ['supports_go_runtime' => false],
        );

        $this->assertSame('raw', $result['framework']);
        $this->assertTrue($result['unsupported_for_target']);
        $this->assertSame('', $result['runtime']);
    }
}
