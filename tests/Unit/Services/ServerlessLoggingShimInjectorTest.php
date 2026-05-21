<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Deploy\ServerlessLoggingShimInjector;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class ServerlessLoggingShimInjectorTest extends TestCase
{
    private function repo(array $files): string
    {
        $dir = storage_path('framework/testing/shim-injector-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        foreach ($files as $name => $contents) {
            File::put($dir.'/'.$name, $contents);
        }

        return $dir;
    }

    public function test_it_plans_the_runtime_entry_file_for_each_language(): void
    {
        $injector = new ServerlessLoggingShimInjector;

        $this->assertSame('index.js', $injector->plan('node', 'main.js')['shim_file']);
        $this->assertSame('__main__.py', $injector->plan('python', 'main.py')['shim_file']);
        $this->assertSame('index.php', $injector->plan('php', 'main.php')['shim_file']);
        $this->assertSame('__dply_shim.go', $injector->plan('go', '')['shim_file']);

        $this->assertFalse($injector->supports('ruby'));
        $this->assertFalse($injector->plan('ruby', 'main')['supported']);
    }

    public function test_it_injects_a_node_shim_as_index_js_wrapping_the_entry_file(): void
    {
        $dir = $this->repo(['main.js' => "exports.main = (a) => ({ body: 'ok' });\n"]);

        try {
            $result = (new ServerlessLoggingShimInjector)->inject($dir, 'node', 'main.js');

            $this->assertTrue($result['ran']);
            $this->assertSame('index.js', $result['shim_file']);
            $this->assertSame('dplyMain', $result['function']);
            $this->assertStringContainsString("require('./main.js')", File::get($dir.'/index.js'));
            $this->assertFileExists($dir.'/main.js');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_it_moves_a_colliding_user_entry_file_aside(): void
    {
        // The raw action is itself index.js — the shim must take that name.
        $dir = $this->repo(['index.js' => "exports.main = (a) => ({ body: 'ok' });\n"]);

        try {
            (new ServerlessLoggingShimInjector)->inject($dir, 'node', 'index.js');

            $this->assertFileExists($dir.'/__dply_action.js');
            $this->assertStringContainsString('exports.main', File::get($dir.'/__dply_action.js'));
            $this->assertStringContainsString("require('./__dply_action.js')", File::get($dir.'/index.js'));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_it_points_package_json_main_at_the_shim(): void
    {
        $dir = $this->repo([
            'main.js' => "exports.main = (a) => a;\n",
            'package.json' => json_encode(['name' => 'fn', 'main' => 'main.js']),
        ]);

        try {
            (new ServerlessLoggingShimInjector)->inject($dir, 'node', 'main.js');

            $package = json_decode(File::get($dir.'/package.json'), true);
            $this->assertSame('index.js', $package['main']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_it_injects_a_go_shim_without_needing_an_entry_file(): void
    {
        $dir = $this->repo(['main.go' => "package main\nfunc Main(a map[string]interface{}) map[string]interface{} { return a }\n"]);

        try {
            $result = (new ServerlessLoggingShimInjector)->inject($dir, 'go', '');

            $this->assertTrue($result['ran']);
            $this->assertSame('DplyMain', $result['function']);
            $this->assertStringContainsString('func DplyMain(', File::get($dir.'/__dply_shim.go'));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_it_injects_python_and_php_shims_as_their_runtime_entry_files(): void
    {
        $dir = $this->repo([
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
    }

    public function test_it_throws_when_the_entry_file_is_missing(): void
    {
        $dir = $this->repo([]);

        try {
            $this->expectException(RuntimeException::class);
            (new ServerlessLoggingShimInjector)->inject($dir, 'node', 'main.js');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_an_unsupported_language_is_a_no_op(): void
    {
        $dir = $this->repo([]);

        try {
            $result = (new ServerlessLoggingShimInjector)->inject($dir, 'ruby', 'main.rb');
            $this->assertFalse($result['ran']);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
