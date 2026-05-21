<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Deploy\ServerlessActionDiscovery;
use App\Services\Deploy\ServerlessRuntimeDetector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ServerlessActionDiscoveryTest extends TestCase
{
    private function capabilities(): array
    {
        return [
            'supports_php_runtime' => true,
            'supports_node_runtime' => true,
            'supports_python_runtime' => true,
            'supports_go_runtime' => true,
            'default_runtime' => 'nodejs:18',
            'default_python_runtime' => 'python:3.11',
            'default_entrypoint' => 'main',
            'default_package' => 'default',
        ];
    }

    private function discover(array $files): array
    {
        $dir = storage_path('framework/testing/action-discovery-'.uniqid());
        File::makeDirectory($dir, 0755, true);

        try {
            foreach ($files as $relative => $contents) {
                $path = $dir.'/'.$relative;
                File::ensureDirectoryExists(dirname($path));
                File::put($path, $contents);
            }

            return (new ServerlessActionDiscovery(new ServerlessRuntimeDetector))
                ->discover($dir, $this->capabilities());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_it_enumerates_actions_from_an_openwhisk_project_manifest(): void
    {
        $actions = $this->discover([
            'project.yml' => <<<'YAML'
            packages:
              default:
                actions:
                  hello:
                    function: src/hello.js
                    runtime: nodejs:18
                    main: handler
                  goodbye:
                    function: src/goodbye.py
                    runtime: python:3.11
            YAML,
        ]);

        $this->assertCount(2, $actions);

        $hello = collect($actions)->firstWhere('name', 'hello');
        $this->assertSame('node', $hello['language']);
        $this->assertSame('nodejs:18', $hello['runtime']);
        $this->assertSame('handler', $hello['entrypoint']);
        $this->assertSame('hello.js', $hello['entry_file']);
        $this->assertSame('src', $hello['source_subdir']);
        $this->assertSame('project_yml', $hello['source']);

        $goodbye = collect($actions)->firstWhere('name', 'goodbye');
        $this->assertSame('python', $goodbye['language']);
        $this->assertSame('main', $goodbye['entrypoint']);
    }

    public function test_it_treats_each_functions_subdirectory_as_an_action(): void
    {
        $actions = $this->discover([
            'functions/api/main.js' => "exports.main = (a) => a;\n",
            'functions/worker/main.py' => "def main(args):\n    return args\n",
        ]);

        $this->assertCount(2, $actions);
        $names = collect($actions)->pluck('name')->sort()->values()->all();
        $this->assertSame(['api', 'worker'], $names);

        $api = collect($actions)->firstWhere('name', 'api');
        $this->assertSame('node', $api['language']);
        $this->assertSame('functions/api', $api['source_subdir']);
        $this->assertSame('functions_dir', $api['source']);
    }

    public function test_it_falls_back_to_a_single_action_for_a_plain_repo(): void
    {
        $actions = $this->discover(['main.go' => "package main\nfunc Main(a map[string]interface{}) map[string]interface{} { return a }\n"]);

        $this->assertCount(1, $actions);
        $this->assertSame('single', $actions[0]['source']);
        $this->assertSame('go', $actions[0]['language']);
        $this->assertSame('main.go', $actions[0]['entry_file']);
        $this->assertSame('', $actions[0]['source_subdir']);
    }

    public function test_an_empty_manifest_falls_through_to_the_next_rule(): void
    {
        // A project.yml that declares no actions must not shadow a real
        // functions/ directory.
        $actions = $this->discover([
            'project.yml' => "parameters:\n  foo: bar\n",
            'functions/solo/main.js' => "exports.main = (a) => a;\n",
        ]);

        $this->assertCount(1, $actions);
        $this->assertSame('solo', $actions[0]['name']);
        $this->assertSame('functions_dir', $actions[0]['source']);
    }
}
