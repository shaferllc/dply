<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerlessExpressAdapterTest;

use App\Services\Deploy\ServerlessExpressAdapter;
use Illuminate\Support\Facades\File;

function repo(array $files): string
{
    $dir = storage_path('framework/testing/express-adapter-'.uniqid());
    File::makeDirectory($dir, 0755, true);
    foreach ($files as $name => $contents) {
        File::put($dir.'/'.$name, $contents);
    }

    return $dir;
}
test('plan recognises an express dependency', function () {
    $dir = repo([
        'package.json' => json_encode(['dependencies' => ['express' => '^4.19.0']]),
    ]);

    try {
        $plan = (new ServerlessExpressAdapter)->plan($dir);
        expect($plan['express'])->toBeTrue();
        expect($plan['handler'])->toBe('index.js');
        expect($plan['function'])->toBe('dplyMain');
    } finally {
        File::deleteDirectory($dir);
    }
});
test('inject writes the adapter and moves a colliding entry aside', function () {
    $dir = repo([
        'package.json' => json_encode(['dependencies' => ['express' => '^4.19.0']]),
        'index.js' => "const express = require('express');\nmodule.exports = express();\n",
    ]);

    try {
        $result = (new ServerlessExpressAdapter)->inject($dir);

        expect($result['ran'])->toBeTrue();
        expect($result['function'])->toBe('dplyMain');

        // The user's app is moved aside; the adapter takes index.js.
        expect($dir.'/__dply_express_app.js')->toBeFile();
        $adapter = File::get($dir.'/index.js');
        $this->assertStringContainsString('dplyMain', $adapter);
        $this->assertStringContainsString("require('./__dply_express_app.js')", $adapter);

        // serverless-http is added and main points at the adapter.
        $package = json_decode(File::get($dir.'/package.json'), true);
        expect($package['dependencies'])->toHaveKey('serverless-http');
        expect($package['main'])->toBe('index.js');
    } finally {
        File::deleteDirectory($dir);
    }
});
test('inject requires a non colliding entry in place', function () {
    $dir = repo([
        'package.json' => json_encode(['main' => 'server.js', 'dependencies' => ['express' => '^4.19.0']]),
        'server.js' => "module.exports = require('express')();\n",
    ]);

    try {
        (new ServerlessExpressAdapter)->inject($dir);

        expect($dir.'/server.js')->toBeFile();
        $this->assertStringContainsString("require('./server.js')", File::get($dir.'/index.js'));
    } finally {
        File::deleteDirectory($dir);
    }
});
test('inject is a no op without an express dependency', function () {
    $dir = repo(['package.json' => json_encode(['dependencies' => ['koa' => '^2.0.0']])]);

    try {
        $result = (new ServerlessExpressAdapter)->inject($dir);
        expect($result['ran'])->toBeFalse();
        $this->assertFileDoesNotExist($dir.'/index.js');
    } finally {
        File::deleteDirectory($dir);
    }
});
