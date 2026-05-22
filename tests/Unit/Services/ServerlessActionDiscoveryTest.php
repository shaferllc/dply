<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerlessActionDiscoveryTest;
use App\Services\Deploy\ServerlessActionDiscovery;
use App\Services\Deploy\ServerlessRuntimeDetector;
use Illuminate\Support\Facades\File;
function capabilities(): array
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
function discover(array $files): array
{
    $dir = storage_path('framework/testing/action-discovery-'.uniqid());
    File::makeDirectory($dir, 0755, true);

    try {
        foreach ($files as $relative => $contents) {
            $path = $dir.'/'.$relative;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
        }

        return discover($dir, capabilities());
    } finally {
        File::deleteDirectory($dir);
    }
}
test('it enumerates actions from an openwhisk project manifest', function () {
    $actions = discover([
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

    expect($actions)->toHaveCount(2);

    $hello = collect($actions)->firstWhere('name', 'hello');
    expect($hello['language'])->toBe('node');
    expect($hello['runtime'])->toBe('nodejs:18');
    expect($hello['entrypoint'])->toBe('handler');
    expect($hello['entry_file'])->toBe('hello.js');
    expect($hello['source_subdir'])->toBe('src');
    expect($hello['source'])->toBe('project_yml');

    $goodbye = collect($actions)->firstWhere('name', 'goodbye');
    expect($goodbye['language'])->toBe('python');
    expect($goodbye['entrypoint'])->toBe('main');
});
test('it treats each functions subdirectory as an action', function () {
    $actions = discover([
        'functions/api/main.js' => "exports.main = (a) => a;\n",
        'functions/worker/main.py' => "def main(args):\n    return args\n",
    ]);

    expect($actions)->toHaveCount(2);
    $names = collect($actions)->pluck('name')->sort()->values()->all();
    expect($names)->toBe(['api', 'worker']);

    $api = collect($actions)->firstWhere('name', 'api');
    expect($api['language'])->toBe('node');
    expect($api['source_subdir'])->toBe('functions/api');
    expect($api['source'])->toBe('functions_dir');
});
test('it falls back to a single action for a plain repo', function () {
    $actions = discover(['main.go' => "package main\nfunc Main(a map[string]interface{}) map[string]interface{} { return a }\n"]);

    expect($actions)->toHaveCount(1);
    expect($actions[0]['source'])->toBe('single');
    expect($actions[0]['language'])->toBe('go');
    expect($actions[0]['entry_file'])->toBe('main.go');
    expect($actions[0]['source_subdir'])->toBe('');
});
test('an empty manifest falls through to the next rule', function () {
    // A project.yml that declares no actions must not shadow a real
    // functions/ directory.
    $actions = discover([
        'project.yml' => "parameters:\n  foo: bar\n",
        'functions/solo/main.js' => "exports.main = (a) => a;\n",
    ]);

    expect($actions)->toHaveCount(1);
    expect($actions[0]['name'])->toBe('solo');
    expect($actions[0]['source'])->toBe('functions_dir');
});
