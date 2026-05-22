<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Deploy\ServerlessGinAdapter;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class ServerlessGinAdapterTest extends TestCase
{
    private function repo(array $files): string
    {
        $dir = storage_path('framework/testing/gin-adapter-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        foreach ($files as $name => $contents) {
            File::put($dir.'/'.$name, $contents);
        }

        return $dir;
    }

    private const GO_MOD = "module example.com/api\n\ngo 1.22\n\nrequire github.com/gin-gonic/gin v1.10.0\n";

    private const MAIN_WITH_ROUTER = "package main\n\nimport (\n\t\"net/http\"\n\t\"github.com/gin-gonic/gin\"\n)\n\nfunc Router() http.Handler {\n\tr := gin.Default()\n\treturn r\n}\n\nfunc main() {}\n";

    public function test_plan_detects_a_gin_app_that_exports_router(): void
    {
        $dir = $this->repo(['go.mod' => self::GO_MOD, 'main.go' => self::MAIN_WITH_ROUTER]);

        try {
            $plan = (new ServerlessGinAdapter)->plan($dir);
            $this->assertTrue($plan['gin']);
            $this->assertTrue($plan['has_router']);
            $this->assertSame('DplyMain', $plan['function']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_inject_adds_the_adapter_source_file(): void
    {
        $dir = $this->repo(['go.mod' => self::GO_MOD, 'main.go' => self::MAIN_WITH_ROUTER]);

        try {
            $result = (new ServerlessGinAdapter)->inject($dir);

            $this->assertTrue($result['ran']);
            $this->assertFileExists($dir.'/dply_adapter.go');
            $this->assertStringContainsString('func DplyMain(', File::get($dir.'/dply_adapter.go'));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_inject_throws_when_the_repo_does_not_export_router(): void
    {
        $dir = $this->repo([
            'go.mod' => self::GO_MOD,
            'main.go' => "package main\n\nfunc main() {}\n",
        ]);

        try {
            $this->expectException(RuntimeException::class);
            (new ServerlessGinAdapter)->inject($dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_inject_is_a_no_op_without_a_gin_dependency(): void
    {
        $dir = $this->repo(['go.mod' => "module example.com/api\n\ngo 1.22\n"]);

        try {
            $result = (new ServerlessGinAdapter)->inject($dir);
            $this->assertFalse($result['ran']);
            $this->assertFileDoesNotExist($dir.'/dply_adapter.go');
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
