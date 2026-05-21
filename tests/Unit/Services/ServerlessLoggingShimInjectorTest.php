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

    public function test_it_plans_a_shim_for_each_supported_language(): void
    {
        $injector = new ServerlessLoggingShimInjector;

        foreach (['node', 'python', 'php', 'go'] as $language) {
            $this->assertTrue($injector->supports($language), $language.' should be supported');
            $this->assertTrue($injector->plan($language, 'main')['supported']);
        }

        $this->assertFalse($injector->supports('ruby'));
        $this->assertFalse($injector->plan('ruby', 'main')['supported']);
    }

    public function test_it_injects_a_node_shim_that_requires_the_entry_file(): void
    {
        $dir = $this->repo(['main.js' => "exports.main = (a) => ({ body: 'ok' });\n"]);

        try {
            $result = (new ServerlessLoggingShimInjector)->inject($dir, 'node', 'main.js');

            $this->assertTrue($result['ran']);
            $this->assertSame('__dply_shim.js', $result['shim_file']);
            $this->assertSame('dplyMain', $result['function']);
            $this->assertFileExists($dir.'/__dply_shim.js');
            $this->assertStringContainsString("require('./main.js')", File::get($dir.'/__dply_shim.js'));
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
            $this->assertFileExists($dir.'/__dply_shim.go');
            $this->assertStringContainsString('func DplyMain(', File::get($dir.'/__dply_shim.go'));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_it_templates_the_entry_file_into_python_and_php_shims(): void
    {
        $dir = $this->repo([
            'handler.py' => "def main(args):\n    return {'body': 'ok'}\n",
            'handler.php' => "<?php\nfunction main(\$a) { return \$a; }\n",
        ]);

        try {
            $injector = new ServerlessLoggingShimInjector;
            $injector->inject($dir, 'python', 'handler.py');
            $injector->inject($dir, 'php', 'handler.php');

            $this->assertStringContainsString('handler.py', File::get($dir.'/__dply_shim.py'));
            $this->assertStringContainsString("require_once __DIR__.'/handler.php'", File::get($dir.'/__dply_shim.php'));
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
