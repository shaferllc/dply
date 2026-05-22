<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless;

use App\Services\Deploy\ServerlessDjangoAdapter;
use App\Services\Deploy\ServerlessExpressAdapter;
use App\Services\Deploy\ServerlessFlaskAdapter;
use App\Services\Deploy\ServerlessGinAdapter;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Runtime-verifies the framework adapters by *executing* them against the
 * real frameworks — Express, Flask, Django, Gin — installed into a scratch
 * directory.
 *
 * Each test builds a minimal app, injects the adapter through its real
 * injector class (the same code a deploy runs), invokes the resulting
 * `dplyMain`/`DplyMain` with a simulated OpenWhisk event, and asserts the
 * adapter routed the request through the framework and mapped the response
 * back to OpenWhisk's {statusCode, headers, body} shape.
 *
 * These tests install packages from the network; they skip cleanly when a
 * toolchain is missing or the install fails (offline CI).
 */
class ServerlessAdapterRuntimeTest extends TestCase
{
    private function tempDir(): string
    {
        $dir = storage_path('framework/testing/adapter-runtime-'.uniqid());
        File::makeDirectory($dir, 0755, true);

        return $dir;
    }

    private function skipUnless(string $binary): void
    {
        if ((new ExecutableFinder)->find($binary) === null) {
            $this->markTestSkipped($binary.' is not available in this environment.');
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function process(array $command, string $dir, array $env = [], int $timeout = 300): Process
    {
        $process = new Process($command, $dir, $env + ['DPLY_LOG_INGEST_URL' => '', 'DPLY_LOG_INGEST_SECRET' => '']);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    /**
     * @param  list<string>  $command
     */
    private function installOrSkip(array $command, string $dir, array $env = []): void
    {
        if (! $this->process($command, $dir, $env)->isSuccessful()) {
            $this->markTestSkipped('Dependency install failed (offline?): '.implode(' ', $command));
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function capture(array $command, string $dir, array $env = []): string
    {
        $process = $this->process($command, $dir, $env);
        $this->assertTrue(
            $process->isSuccessful(),
            'Adapter runtime failed: '.trim($process->getErrorOutput()."\n".$process->getOutput()),
        );

        return trim($process->getOutput());
    }

    public function test_the_express_adapter_routes_a_request_through_express(): void
    {
        $this->skipUnless('node');
        $this->skipUnless('npm');
        $dir = $this->tempDir();

        try {
            File::put($dir.'/package.json', json_encode([
                'name' => 'fn', 'type' => 'commonjs', 'main' => 'app.js',
                'dependencies' => ['express' => '^4.19.0'],
            ]));
            File::put($dir.'/app.js', "const e = require('express');\nconst a = e();\na.get('/hi', (req, res) => res.status(201).json({ msg: 'hello', m: req.method }));\nmodule.exports = a;\n");

            $this->installOrSkip(['npm', 'install', 'express', 'serverless-http'], $dir);
            (new ServerlessExpressAdapter)->inject($dir);

            File::put($dir.'/drive.js', "require('./index.js').dplyMain({__ow_method:'get',__ow_path:'/hi'})"
                ."\n  .then(r => process.stdout.write(JSON.stringify({s:r.statusCode,b:r.body})))"
                ."\n  .catch(e => { process.stderr.write(String(e.stack||e)); process.exit(1); });\n");

            $result = json_decode($this->capture(['node', 'drive.js'], $dir), true);
            $this->assertSame(201, $result['s']);
            $this->assertStringContainsString('hello', $result['b']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_the_flask_adapter_routes_a_request_through_flask(): void
    {
        $this->skipUnless('python3');
        $dir = $this->tempDir();

        try {
            File::put($dir.'/app.py', "from flask import Flask\napp = Flask(__name__)\n\n@app.get('/hi')\ndef hi():\n    return {'msg': 'hello'}, 201\n");
            $this->installOrSkip(['python3', '-m', 'pip', 'install', '--target', 'libs', 'flask'], $dir);

            (new ServerlessFlaskAdapter)->inject($dir);

            File::put($dir.'/drive.py', $this->pythonDriver());
            $result = json_decode($this->capture(['python3', 'drive.py'], $dir, ['PYTHONPATH' => $dir.'/libs']), true);

            $this->assertSame(201, $result['statusCode']);
            $this->assertStringContainsString('hello', $result['body']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_the_django_adapter_routes_a_request_through_django(): void
    {
        $this->skipUnless('python3');
        $dir = $this->tempDir();

        try {
            File::makeDirectory($dir.'/myproject', 0755, true);
            File::put($dir.'/myproject/__init__.py', '');
            File::put($dir.'/myproject/settings.py', "SECRET_KEY = 'test'\nDEBUG = True\nALLOWED_HOSTS = ['*']\nROOT_URLCONF = 'myproject.urls'\nINSTALLED_APPS = []\nMIDDLEWARE = []\n");
            File::put($dir.'/myproject/urls.py', "from django.http import JsonResponse\nfrom django.urls import path\n\ndef hi(request):\n    return JsonResponse({'msg': 'hello'}, status=201)\n\nurlpatterns = [path('hi', hi)]\n");
            File::put($dir.'/myproject/wsgi.py', "import os\nfrom django.core.wsgi import get_wsgi_application\nos.environ.setdefault('DJANGO_SETTINGS_MODULE', 'myproject.settings')\napplication = get_wsgi_application()\n");

            $this->installOrSkip(['python3', '-m', 'pip', 'install', '--target', 'libs', 'django'], $dir);

            (new ServerlessDjangoAdapter)->inject($dir);

            File::put($dir.'/drive.py', $this->pythonDriver('/hi'));
            $result = json_decode($this->capture(['python3', 'drive.py'], $dir, ['PYTHONPATH' => $dir.'/libs']), true);

            $this->assertSame(201, $result['statusCode']);
            $this->assertStringContainsString('hello', $result['body']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_the_gin_adapter_drives_the_repos_http_handler(): void
    {
        $this->skipUnless('go');
        $dir = $this->tempDir();

        try {
            File::put($dir.'/go.mod', "module dplyginruntime\n\ngo 1.22\n");

            // The Gin adapter imports only the standard library — it drives
            // whatever http.Handler the repo's Router() returns, and a
            // *gin.Engine *is* an http.Handler. A stdlib ServeMux stands in
            // so the adapter's request translation is exercised end to end
            // without fetching the gin module.
            File::put($dir.'/router.go', "package main\n\nimport \"net/http\"\n\nfunc Router() http.Handler {\n\tmux := http.NewServeMux()\n\tmux.HandleFunc(\"/hi\", func(w http.ResponseWriter, r *http.Request) {\n\t\tw.Header().Set(\"Content-Type\", \"application/json\")\n\t\tw.WriteHeader(201)\n\t\t_, _ = w.Write([]byte(`{\"msg\":\"hello\"}`))\n\t})\n\treturn mux\n}\n");
            File::put($dir.'/'.ServerlessGinAdapter::HANDLER_FILENAME, File::get(resource_path('serverless/adapters/gin.go')));
            File::put($dir.'/drive.go', "package main\n\nimport (\n\t\"encoding/json\"\n\t\"fmt\"\n)\n\nfunc main() {\n\tr := DplyMain(map[string]interface{}{\"__ow_method\": \"get\", \"__ow_path\": \"/hi\"})\n\tb, _ := json.Marshal(r)\n\tfmt.Print(string(b))\n}\n");

            $result = json_decode($this->capture(['go', 'run', '.'], $dir), true);
            $this->assertSame(201, $result['statusCode']);
            $this->assertStringContainsString('hello', $result['body']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * A Python driver that loads the injected `__main__.py` adapter by path
     * (so the `__main__` name does not clash) and invokes dplyMain.
     */
    private function pythonDriver(string $path = '/hi'): string
    {
        return "import importlib.util, json\n"
            ."spec = importlib.util.spec_from_file_location('dplyadapter', '__main__.py')\n"
            ."mod = importlib.util.module_from_spec(spec)\n"
            ."spec.loader.exec_module(mod)\n"
            ."print(json.dumps(mod.dplyMain({'__ow_method': 'get', '__ow_path': '".$path."'})))\n";
    }
}
