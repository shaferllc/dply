<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Deploy\ServerlessExpressAdapter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ServerlessExpressAdapterTest extends TestCase
{
    private function repo(array $files): string
    {
        $dir = storage_path('framework/testing/express-adapter-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        foreach ($files as $name => $contents) {
            File::put($dir.'/'.$name, $contents);
        }

        return $dir;
    }

    public function test_plan_recognises_an_express_dependency(): void
    {
        $dir = $this->repo([
            'package.json' => json_encode(['dependencies' => ['express' => '^4.19.0']]),
        ]);

        try {
            $plan = (new ServerlessExpressAdapter)->plan($dir);
            $this->assertTrue($plan['express']);
            $this->assertSame('index.js', $plan['handler']);
            $this->assertSame('dplyMain', $plan['function']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_inject_writes_the_adapter_and_moves_a_colliding_entry_aside(): void
    {
        $dir = $this->repo([
            'package.json' => json_encode(['dependencies' => ['express' => '^4.19.0']]),
            'index.js' => "const express = require('express');\nmodule.exports = express();\n",
        ]);

        try {
            $result = (new ServerlessExpressAdapter)->inject($dir);

            $this->assertTrue($result['ran']);
            $this->assertSame('dplyMain', $result['function']);

            // The user's app is moved aside; the adapter takes index.js.
            $this->assertFileExists($dir.'/__dply_express_app.js');
            $adapter = File::get($dir.'/index.js');
            $this->assertStringContainsString('dplyMain', $adapter);
            $this->assertStringContainsString("require('./__dply_express_app.js')", $adapter);

            // serverless-http is added and main points at the adapter.
            $package = json_decode(File::get($dir.'/package.json'), true);
            $this->assertArrayHasKey('serverless-http', $package['dependencies']);
            $this->assertSame('index.js', $package['main']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_inject_requires_a_non_colliding_entry_in_place(): void
    {
        $dir = $this->repo([
            'package.json' => json_encode(['main' => 'server.js', 'dependencies' => ['express' => '^4.19.0']]),
            'server.js' => "module.exports = require('express')();\n",
        ]);

        try {
            (new ServerlessExpressAdapter)->inject($dir);

            $this->assertFileExists($dir.'/server.js');
            $this->assertStringContainsString("require('./server.js')", File::get($dir.'/index.js'));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_inject_is_a_no_op_without_an_express_dependency(): void
    {
        $dir = $this->repo(['package.json' => json_encode(['dependencies' => ['koa' => '^2.0.0']])]);

        try {
            $result = (new ServerlessExpressAdapter)->inject($dir);
            $this->assertFalse($result['ran']);
            $this->assertFileDoesNotExist($dir.'/index.js');
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
