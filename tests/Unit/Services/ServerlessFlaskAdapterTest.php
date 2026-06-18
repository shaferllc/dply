<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerlessFlaskAdapterTest;

use App\Modules\Deploy\Services\ServerlessFlaskAdapter;
use Illuminate\Support\Facades\File;
use RuntimeException;

function repo(array $files): string
{
    $dir = storage_path('framework/testing/flask-adapter-'.uniqid());
    File::makeDirectory($dir, 0755, true);
    foreach ($files as $name => $contents) {
        File::put($dir.'/'.$name, $contents);
    }

    return $dir;
}
test('plan locates the flask app and its variable', function () {
    $dir = repo(['app.py' => "from flask import Flask\napp = Flask(__name__)\n"]);

    try {
        $plan = (new ServerlessFlaskAdapter)->plan($dir);
        expect($plan['flask'])->toBeTrue();
        expect($plan['module_file'])->toBe('app.py');
        expect($plan['app_var'])->toBe('app');
        expect($plan['handler'])->toBe('__main__.py');
    } finally {
        File::deleteDirectory($dir);
    }
});
test('plan recognises a non default app variable', function () {
    $dir = repo(['wsgi.py' => "from flask import Flask\napplication = Flask(__name__)\n"]);

    try {
        $plan = (new ServerlessFlaskAdapter)->plan($dir);
        expect($plan['module_file'])->toBe('wsgi.py');
        expect($plan['app_var'])->toBe('application');
    } finally {
        File::deleteDirectory($dir);
    }
});
test('inject writes the adapter as the python entry', function () {
    $dir = repo(['main.py' => "from flask import Flask\napp = Flask(__name__)\n"]);

    try {
        $result = (new ServerlessFlaskAdapter)->inject($dir);

        expect($result['ran'])->toBeTrue();
        expect($result['function'])->toBe('dplyMain');
        $adapter = File::get($dir.'/__main__.py');
        $this->assertStringContainsString('"main.py"', $adapter);
        $this->assertStringContainsString('getattr(_module, "app")', $adapter);
    } finally {
        File::deleteDirectory($dir);
    }
});
test('inject moves a colliding app module aside', function () {
    $dir = repo(['__main__.py' => "from flask import Flask\napp = Flask(__name__)\n"]);

    try {
        (new ServerlessFlaskAdapter)->inject($dir);

        expect($dir.'/__dply_flask_app.py')->toBeFile();
        $this->assertStringContainsString('"__dply_flask_app.py"', File::get($dir.'/__main__.py'));
    } finally {
        File::deleteDirectory($dir);
    }
});
test('inject throws when no flask app is found', function () {
    $dir = repo(['app.py' => "print('not a flask app')\n"]);

    try {
        $this->expectException(RuntimeException::class);
        (new ServerlessFlaskAdapter)->inject($dir);
    } finally {
        File::deleteDirectory($dir);
    }
});
