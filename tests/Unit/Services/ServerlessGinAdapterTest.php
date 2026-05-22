<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerlessGinAdapterTest;
use RuntimeException;

use App\Services\Deploy\ServerlessGinAdapter;
use Illuminate\Support\Facades\File;
function repo(array $files): string
{
    $dir = storage_path('framework/testing/gin-adapter-'.uniqid());
    File::makeDirectory($dir, 0755, true);
    foreach ($files as $name => $contents) {
        File::put($dir.'/'.$name, $contents);
    }

    return $dir;
}
const GO_MOD = "module example.com/api\n\ngo 1.22\n\nrequire github.com/gin-gonic/gin v1.10.0\n";
const MAIN_WITH_ROUTER = "package main\n\nimport (\n\t\"net/http\"\n\t\"github.com/gin-gonic/gin\"\n)\n\nfunc Router() http.Handler {\n\tr := gin.Default()\n\treturn r\n}\n\nfunc main() {}\n";
test('plan detects a gin app that exports router', function () {
    $dir = repo(['go.mod' => GO_MOD, 'main.go' => MAIN_WITH_ROUTER]);

    try {
        $plan = (new ServerlessGinAdapter)->plan($dir);
        expect($plan['gin'])->toBeTrue();
        expect($plan['has_router'])->toBeTrue();
        expect($plan['function'])->toBe('DplyMain');
    } finally {
        File::deleteDirectory($dir);
    }
});
test('inject adds the adapter source file', function () {
    $dir = repo(['go.mod' => GO_MOD, 'main.go' => MAIN_WITH_ROUTER]);

    try {
        $result = (new ServerlessGinAdapter)->inject($dir);

        expect($result['ran'])->toBeTrue();
        expect($dir.'/dply_adapter.go')->toBeFile();
        $this->assertStringContainsString('func DplyMain(', File::get($dir.'/dply_adapter.go'));
    } finally {
        File::deleteDirectory($dir);
    }
});
test('inject throws when the repo does not export router', function () {
    $dir = repo([
        'go.mod' => GO_MOD,
        'main.go' => "package main\n\nfunc main() {}\n",
    ]);

    try {
        $this->expectException(RuntimeException::class);
        (new ServerlessGinAdapter)->inject($dir);
    } finally {
        File::deleteDirectory($dir);
    }
});
test('inject is a no op without a gin dependency', function () {
    $dir = repo(['go.mod' => "module example.com/api\n\ngo 1.22\n"]);

    try {
        $result = (new ServerlessGinAdapter)->inject($dir);
        expect($result['ran'])->toBeFalse();
        $this->assertFileDoesNotExist($dir.'/dply_adapter.go');
    } finally {
        File::deleteDirectory($dir);
    }
});
