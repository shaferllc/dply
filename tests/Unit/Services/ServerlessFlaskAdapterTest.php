<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Deploy\ServerlessFlaskAdapter;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class ServerlessFlaskAdapterTest extends TestCase
{
    private function repo(array $files): string
    {
        $dir = storage_path('framework/testing/flask-adapter-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        foreach ($files as $name => $contents) {
            File::put($dir.'/'.$name, $contents);
        }

        return $dir;
    }

    public function test_plan_locates_the_flask_app_and_its_variable(): void
    {
        $dir = $this->repo(['app.py' => "from flask import Flask\napp = Flask(__name__)\n"]);

        try {
            $plan = (new ServerlessFlaskAdapter)->plan($dir);
            $this->assertTrue($plan['flask']);
            $this->assertSame('app.py', $plan['module_file']);
            $this->assertSame('app', $plan['app_var']);
            $this->assertSame('__main__.py', $plan['handler']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_plan_recognises_a_non_default_app_variable(): void
    {
        $dir = $this->repo(['wsgi.py' => "from flask import Flask\napplication = Flask(__name__)\n"]);

        try {
            $plan = (new ServerlessFlaskAdapter)->plan($dir);
            $this->assertSame('wsgi.py', $plan['module_file']);
            $this->assertSame('application', $plan['app_var']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_inject_writes_the_adapter_as_the_python_entry(): void
    {
        $dir = $this->repo(['main.py' => "from flask import Flask\napp = Flask(__name__)\n"]);

        try {
            $result = (new ServerlessFlaskAdapter)->inject($dir);

            $this->assertTrue($result['ran']);
            $this->assertSame('dplyMain', $result['function']);
            $adapter = File::get($dir.'/__main__.py');
            $this->assertStringContainsString('"main.py"', $adapter);
            $this->assertStringContainsString('getattr(_module, "app")', $adapter);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_inject_moves_a_colliding_app_module_aside(): void
    {
        $dir = $this->repo(['__main__.py' => "from flask import Flask\napp = Flask(__name__)\n"]);

        try {
            (new ServerlessFlaskAdapter)->inject($dir);

            $this->assertFileExists($dir.'/__dply_flask_app.py');
            $this->assertStringContainsString('"__dply_flask_app.py"', File::get($dir.'/__main__.py'));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_inject_throws_when_no_flask_app_is_found(): void
    {
        $dir = $this->repo(['app.py' => "print('not a flask app')\n"]);

        try {
            $this->expectException(RuntimeException::class);
            (new ServerlessFlaskAdapter)->inject($dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
