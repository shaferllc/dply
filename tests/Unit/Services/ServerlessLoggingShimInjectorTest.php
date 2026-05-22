<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerlessLoggingShimInjectorTest;
use App\Services\Deploy\ServerlessLoggingShimInjector;
use Illuminate\Support\Facades\File;
function repo(array $files): string
{
    $dir = storage_path('framework/testing/shim-injector-'.uniqid());
    File::makeDirectory($dir, 0755, true);
    foreach ($files as $name => $contents) {
        File::put($dir.'/'.$name, $contents);
    }

    return $dir;
}
test('it plans the runtime entry file for each language', function () {
    $injector = new ServerlessLoggingShimInjector;

    expect($injector->plan('node', 'main.js')['shim_file'])->toBe('index.js');
    expect($injector->plan('python', 'main.py')['shim_file'])->toBe('__main__.py');
    expect($injector->plan('php', 'main.php')['shim_file'])->toBe('index.php');
    expect($injector->plan('go', '')['shim_file'])->toBe('dply_shim.go');

    expect($injector->supports('ruby'))->toBeFalse();
    expect($injector->plan('ruby', 'main')['supported'])->toBeFalse();
});
test('it injects a node shim as index js wrapping the entry file', function () {
    $dir = repo(['main.js' => "exports.main = (a) => ({ body: 'ok' });\n"]);

    try {
        $result = (new ServerlessLoggingShimInjector)->inject($dir, 'node', 'main.js');

        expect($result['ran'])->toBeTrue();
        expect($result['shim_file'])->toBe('index.js');
        expect($result['function'])->toBe('dplyMain');
        $this->assertStringContainsString("require('./main.js')", File::get($dir.'/index.js'));
        expect($dir.'/main.js')->toBeFile();
    } finally {
        File::deleteDirectory($dir);
    }
});
test('it moves a colliding user entry file aside', function () {
    // The raw action is itself index.js — the shim must take that name.
    $dir = repo(['index.js' => "exports.main = (a) => ({ body: 'ok' });\n"]);

    try {
        (new ServerlessLoggingShimInjector)->inject($dir, 'node', 'index.js');

        expect($dir.'/__dply_action.js')->toBeFile();
        $this->assertStringContainsString('exports.main', File::get($dir.'/__dply_action.js'));
        $this->assertStringContainsString("require('./__dply_action.js')", File::get($dir.'/index.js'));
    } finally {
        File::deleteDirectory($dir);
    }
});
test('it points package json main at the shim', function () {
    $dir = repo([
        'main.js' => "exports.main = (a) => a;\n",
        'package.json' => json_encode(['name' => 'fn', 'main' => 'main.js']),
    ]);

    try {
        (new ServerlessLoggingShimInjector)->inject($dir, 'node', 'main.js');

        $package = json_decode(File::get($dir.'/package.json'), true);
        expect($package['main'])->toBe('index.js');
    } finally {
        File::deleteDirectory($dir);
    }
});
test('it injects a go shim without needing an entry file', function () {
    $dir = repo(['main.go' => "package main\nfunc Main(a map[string]interface{}) map[string]interface{} { return a }\n"]);

    try {
        $result = (new ServerlessLoggingShimInjector)->inject($dir, 'go', '');

        expect($result['ran'])->toBeTrue();
        expect($result['function'])->toBe('DplyMain');
        $this->assertStringContainsString('func DplyMain(', File::get($dir.'/dply_shim.go'));
    } finally {
        File::deleteDirectory($dir);
    }
});
test('it injects python and php shims as their runtime entry files', function () {
    $dir = repo([
        'handler.py' => "def main(args):\n    return {'body': 'ok'}\n",
        'main.php' => "<?php\nfunction main(\$a) { return \$a; }\n",
    ]);

    try {
        $injector = new ServerlessLoggingShimInjector;
        $injector->inject($dir, 'python', 'handler.py');
        $injector->inject($dir, 'php', 'main.php');

        $this->assertStringContainsString('handler.py', File::get($dir.'/__main__.py'));
        $this->assertStringContainsString("require_once __DIR__.'/main.php'", File::get($dir.'/index.php'));
    } finally {
        File::deleteDirectory($dir);
    }
});
test('it throws when the entry file is missing', function () {
    $dir = repo([]);

    try {
        $this->expectException(RuntimeException::class);
        (new ServerlessLoggingShimInjector)->inject($dir, 'node', 'main.js');
    } finally {
        File::deleteDirectory($dir);
    }
});
test('an unsupported language is a no op', function () {
    $dir = repo([]);

    try {
        $result = (new ServerlessLoggingShimInjector)->inject($dir, 'ruby', 'main.rb');
        expect($result['ran'])->toBeFalse();
    } finally {
        File::deleteDirectory($dir);
    }
});
