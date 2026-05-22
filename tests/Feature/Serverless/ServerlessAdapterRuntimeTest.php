<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessAdapterRuntimeTest;

use App\Services\Deploy\ServerlessDjangoAdapter;
use App\Services\Deploy\ServerlessExpressAdapter;
use App\Services\Deploy\ServerlessFlaskAdapter;
use App\Services\Deploy\ServerlessGinAdapter;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

function tempDir(): string
{
    $dir = storage_path('framework/testing/adapter-runtime-'.uniqid());
    File::makeDirectory($dir, 0755, true);

    return $dir;
}
function skipUnless(string $binary): void
{
    if ((new ExecutableFinder)->find($binary) === null) {
        Assert::markTestSkipped($binary.' is not available in this environment.');
    }
}
/**
 * @param  list<string>  $command
 */
function process(array $command, string $dir, array $env = [], int $timeout = 300): Process
{
    $process = new Process($command, $dir, $env + ['DPLY_LOG_INGEST_URL' => '', 'DPLY_LOG_INGEST_SECRET' => '']);
    $process->setTimeout($timeout);
    $process->run();

    return $process;
}
/**
 * @param  list<string>  $command
 */
function installOrSkip(array $command, string $dir, array $env = []): void
{
    if (! process($command, $dir, $env)->isSuccessful()) {
        Assert::markTestSkipped('Dependency install failed (offline?): '.implode(' ', $command));
    }
}
/**
 * @param  list<string>  $command
 */
function capture(array $command, string $dir, array $env = []): string
{
    $process = process($command, $dir, $env);
    expect($process->isSuccessful())->toBeTrue('Adapter runtime failed: '.trim($process->getErrorOutput()."\n".$process->getOutput()));

    return trim($process->getOutput());
}
test('the express adapter routes a request through express', function () {
    skipUnless('node');
    skipUnless('npm');
    $dir = tempDir();

    try {
        File::put($dir.'/package.json', json_encode([
            'name' => 'fn', 'type' => 'commonjs', 'main' => 'app.js',
            'dependencies' => ['express' => '^4.19.0'],
        ]));
        File::put($dir.'/app.js', "const e = require('express');\nconst a = e();\na.get('/hi', (req, res) => res.status(201).json({ msg: 'hello', m: req.method }));\nmodule.exports = a;\n");

        installOrSkip(['npm', 'install', 'express', 'serverless-http'], $dir);
        (new ServerlessExpressAdapter)->inject($dir);

        File::put($dir.'/drive.js', "require('./index.js').dplyMain({__ow_method:'get',__ow_path:'/hi'})"
            ."\n  .then(r => process.stdout.write(JSON.stringify({s:r.statusCode,b:r.body})))"
            ."\n  .catch(e => { process.stderr.write(String(e.stack||e)); process.exit(1); });\n");

        $result = json_decode(capture(['node', 'drive.js'], $dir), true);
        expect($result['s'])->toBe(201);
        $this->assertStringContainsString('hello', $result['b']);
    } finally {
        File::deleteDirectory($dir);
    }
});
test('the flask adapter routes a request through flask', function () {
    skipUnless('python3');
    $dir = tempDir();

    try {
        File::put($dir.'/app.py', "from flask import Flask\napp = Flask(__name__)\n\n@app.get('/hi')\ndef hi():\n    return {'msg': 'hello'}, 201\n");
        installOrSkip(['python3', '-m', 'pip', 'install', '--target', 'libs', 'flask'], $dir);

        (new ServerlessFlaskAdapter)->inject($dir);

        File::put($dir.'/drive.py', pythonDriver());
        $result = json_decode(capture(['python3', 'drive.py'], $dir, ['PYTHONPATH' => $dir.'/libs']), true);

        expect($result['statusCode'])->toBe(201);
        $this->assertStringContainsString('hello', $result['body']);
    } finally {
        File::deleteDirectory($dir);
    }
});
test('the django adapter routes a request through django', function () {
    skipUnless('python3');
    $dir = tempDir();

    try {
        File::makeDirectory($dir.'/myproject', 0755, true);
        File::put($dir.'/myproject/__init__.py', '');
        File::put($dir.'/myproject/settings.py', "SECRET_KEY = 'test'\nDEBUG = True\nALLOWED_HOSTS = ['*']\nROOT_URLCONF = 'myproject.urls'\nINSTALLED_APPS = []\nMIDDLEWARE = []\n");
        File::put($dir.'/myproject/urls.py', "from django.http import JsonResponse\nfrom django.urls import path\n\ndef hi(request):\n    return JsonResponse({'msg': 'hello'}, status=201)\n\nurlpatterns = [path('hi', hi)]\n");
        File::put($dir.'/myproject/wsgi.py', "import os\nfrom django.core.wsgi import get_wsgi_application\nos.environ.setdefault('DJANGO_SETTINGS_MODULE', 'myproject.settings')\napplication = get_wsgi_application()\n");

        installOrSkip(['python3', '-m', 'pip', 'install', '--target', 'libs', 'django'], $dir);

        (new ServerlessDjangoAdapter)->inject($dir);

        File::put($dir.'/drive.py', pythonDriver('/hi'));
        $result = json_decode(capture(['python3', 'drive.py'], $dir, ['PYTHONPATH' => $dir.'/libs']), true);

        expect($result['statusCode'])->toBe(201);
        $this->assertStringContainsString('hello', $result['body']);
    } finally {
        File::deleteDirectory($dir);
    }
});
test('the gin adapter drives the repos http handler', function () {
    skipUnless('go');
    $dir = tempDir();

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

        $result = json_decode(capture(['go', 'run', '.'], $dir), true);
        expect($result['statusCode'])->toBe(201);
        $this->assertStringContainsString('hello', $result['body']);
    } finally {
        File::deleteDirectory($dir);
    }
});
/**
 * A Python driver that loads the injected `__main__.py` adapter by path
 * (so the `__main__` name does not clash) and invokes dplyMain.
 */
function pythonDriver(string $path = '/hi'): string
{
    return "import importlib.util, json\n"
        ."spec = importlib.util.spec_from_file_location('dplyadapter', '__main__.py')\n"
        ."mod = importlib.util.module_from_spec(spec)\n"
        ."spec.loader.exec_module(mod)\n"
        ."print(json.dumps(mod.dplyMain({'__ow_method': 'get', '__ow_path': '".$path."'})))\n";
}
