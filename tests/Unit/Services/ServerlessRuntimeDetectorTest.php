<?php

namespace Tests\Unit\Services\ServerlessRuntimeDetectorTest;

use App\Services\Deploy\ServerlessRuntimeDetector;
use Illuminate\Support\Facades\File;

test('ruby repo falls through to unknown since do functions has no ruby runtime', function () {
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

        expect($result['framework'])->toBe('unknown');
        expect($result['language'])->toBe('unknown');
    } finally {
        File::deleteDirectory($dir);
    }
});

test('detects symfony via framework bundle', function () {
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

        expect($result['framework'])->toBe('symfony');
        expect($result['language'])->toBe('php');
        expect($result['confidence'])->toBe('medium');
    } finally {
        File::deleteDirectory($dir);
    }
});

test('detects django with manage py', function () {
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

        expect($result['framework'])->toBe('django');
        expect($result['language'])->toBe('python');
        expect($result['confidence'])->toBe('high');
    } finally {
        File::deleteDirectory($dir);
    }
});

test('detects fastapi from requirements', function () {
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

        expect($result['framework'])->toBe('fastapi');
        expect($result['language'])->toBe('python');
    } finally {
        File::deleteDirectory($dir);
    }
});

test('detects laravel octane flag from composer', function () {
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

        expect($result['framework'])->toBe('laravel');
        expect($result['laravel_octane'])->toBeTrue();
    } finally {
        File::deleteDirectory($dir);
    }
});

/** Capabilities advertising all four DigitalOcean Functions runtimes. */
function doCapabilities(array $overrides = []): array
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
function detectRepo(array $files, array $capabilityOverrides = []): array
{
    $dir = storage_path('framework/testing/serverless-detector-'.uniqid());
    File::makeDirectory($dir, 0755, true);

    try {
        foreach ($files as $relative => $contents) {
            $path = $dir.'/'.$relative;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
        }

        return (new ServerlessRuntimeDetector)->detect($dir, doCapabilities($capabilityOverrides));
    } finally {
        File::deleteDirectory($dir);
    }
}

test('detects a raw node action from main js', function () {
    $result = detectRepo(['main.js' => "exports.main = function (args) {\n  return { body: 'ok' };\n};\n"]);

    expect($result['framework'])->toBe('raw');
    expect($result['deploy_kind'])->toBe('raw');
    expect($result['language'])->toBe('node');
    expect($result['entry_file'])->toBe('main.js');
    expect($result['entrypoint'])->toBe('main');
    expect($result['runtime'])->toBe('nodejs:18');
    expect($result['unsupported_for_target'])->toBeFalse();
});

test('detects a raw python action from main py', function () {
    $result = detectRepo(['main.py' => "def main(args):\n    return {'body': 'ok'}\n"]);

    expect($result['framework'])->toBe('raw');
    expect($result['language'])->toBe('python');
    expect($result['entry_file'])->toBe('main.py');
    expect($result['runtime'])->toBe('python:3.11');
});

test('detects a raw php action from main php', function () {
    $result = detectRepo(['main.php' => "<?php\nfunction main(array \$args): array\n{\n    return ['body' => 'ok'];\n}\n"]);

    expect($result['framework'])->toBe('raw');
    expect($result['language'])->toBe('php');
    expect($result['entry_file'])->toBe('main.php');
    expect($result['runtime'])->toBe('php:8.3');
});

test('detects a raw go action from main go', function () {
    $result = detectRepo(['main.go' => "package main\n\nfunc Main(args map[string]interface{}) map[string]interface{} {\n  return args\n}\n"]);

    expect($result['framework'])->toBe('raw');
    expect($result['language'])->toBe('go');
    expect($result['entry_file'])->toBe('main.go');
    expect($result['runtime'])->toBe('go:1.22');
});

test('a file without a main symbol is not a raw action', function () {
    $result = detectRepo(['main.js' => "console.log('not an action');\n"]);

    expect($result['framework'])->toBe('unknown');
    expect($result['deploy_kind'])->toBe('unknown');
});

test('detects an openwhisk project yml as a raw multi action package', function () {
    $result = detectRepo(['project.yml' => "packages:\n  default:\n    actions:\n      hello:\n        function: hello.js\n"]);

    expect($result['framework'])->toBe('raw');
    expect($result['deploy_kind'])->toBe('raw');
    expect($result['language'])->toBe('mixed');
    expect($result['confidence'])->toBe('high');
});

test('framework markers win over a raw main file', function () {
    // A Laravel repo that also happens to carry a root main.php must
    // still be classified as the framework — ladder step 1 beats step 4.
    $result = detectRepo([
        'artisan' => "#!/usr/bin/env php\n",
        'bootstrap/app.php' => "<?php\n",
        'composer.json' => json_encode(['require' => ['laravel/framework' => '^12.0']]),
        'main.php' => "<?php\nfunction main(\$args) { return \$args; }\n",
    ]);

    expect($result['framework'])->toBe('laravel');
    expect($result['deploy_kind'])->toBe('framework');
});

test('detects an express app as a framework', function () {
    $result = detectRepo([
        'package.json' => json_encode(['dependencies' => ['express' => '^4.19.0']]),
        'index.js' => "const express = require('express');\nmodule.exports = express();\n",
    ]);

    expect($result['framework'])->toBe('express');
    expect($result['deploy_kind'])->toBe('framework');
    expect($result['language'])->toBe('node');
});

test('detects a gin app as a framework', function () {
    $result = detectRepo([
        'go.mod' => "module example.com/api\n\ngo 1.22\n\nrequire github.com/gin-gonic/gin v1.10.0\n",
        'main.go' => "package main\nfunc main() {}\n",
    ]);

    expect($result['framework'])->toBe('gin');
    expect($result['deploy_kind'])->toBe('framework');
    expect($result['language'])->toBe('go');
    expect($result['runtime'])->toBe('go:1.22');
});

test('a raw action is unsupported when the target lacks that runtime', function () {
    $result = detectRepo(['main.go' => "package main\nfunc Main(a map[string]interface{}) map[string]interface{} { return a }\n"], ['supports_go_runtime' => false]);

    expect($result['framework'])->toBe('raw');
    expect($result['unsupported_for_target'])->toBeTrue();
    expect($result['runtime'])->toBe('');
});
