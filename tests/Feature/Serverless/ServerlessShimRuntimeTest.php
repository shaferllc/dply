<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless;

use App\Services\Deploy\ServerlessLoggingShimInjector;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Runtime-verifies the per-language logging shims by *executing* them in
 * their real interpreters — Node, Python, PHP, Go — against a simulated
 * OpenWhisk web-action event, not merely syntax-checking them.
 *
 * Each test injects the shim around a trivial user action exactly as
 * ServerlessLoggingShimInjector would, invokes `dplyMain`/`DplyMain`, and
 * asserts the shim called through and mapped the result to OpenWhisk's
 * {statusCode, headers, body} shape. With no DPLY_LOG_INGEST_URL set the
 * fire-and-forget report is a no-op, so these tests need no network.
 */
class ServerlessShimRuntimeTest extends TestCase
{
    private function tempDir(): string
    {
        $dir = storage_path('framework/testing/shim-runtime-'.uniqid());
        File::makeDirectory($dir, 0755, true);

        return $dir;
    }

    private function shim(string $file, string $entry): string
    {
        return str_replace(
            '{{DPLY_ENTRY}}',
            $entry,
            File::get(resource_path('serverless/shims/'.$file)),
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function execute(array $command, string $dir): string
    {
        $process = new Process($command, $dir, ['DPLY_LOG_INGEST_URL' => '', 'DPLY_LOG_INGEST_SECRET' => '']);
        $process->setTimeout(120);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            'Shim runtime failed: '.trim($process->getErrorOutput()."\n".$process->getOutput()),
        );

        return trim($process->getOutput());
    }

    private function skipUnless(string $binary): void
    {
        if ((new ExecutableFinder)->find($binary) === null) {
            $this->markTestSkipped($binary.' is not available in this environment.');
        }
    }

    public function test_the_node_shim_executes_and_translates_the_result(): void
    {
        $this->skipUnless('node');
        $dir = $this->tempDir();

        try {
            File::put($dir.'/main.js', "exports.main = (a) => ({ statusCode: 201, body: 'ok:' + a.__ow_method });\n");

            // Inject exactly as a deploy would — this writes the shim as
            // index.js and a package.json with "type": "commonjs" so the
            // CommonJS shim loads (verifying that real bug fix end to end).
            (new ServerlessLoggingShimInjector)->inject($dir, 'node', 'main.js');

            File::put($dir.'/drive.js', "require('./index.js').dplyMain({__ow_method:'post',__ow_path:'/x'})"
                ."\n  .then(r => process.stdout.write(JSON.stringify(r)))"
                ."\n  .catch(e => { process.stderr.write(String(e)); process.exit(1); });\n");

            $result = json_decode($this->execute(['node', 'drive.js'], $dir), true);

            $this->assertSame(201, $result['statusCode']);
            $this->assertSame('ok:post', $result['body']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_the_python_shim_executes_and_translates_the_result(): void
    {
        $this->skipUnless('python3');
        $dir = $this->tempDir();

        try {
            File::put($dir.'/action.py', "def main(args):\n    return {'statusCode': 201, 'body': 'ok:' + args['__ow_method']}\n");
            File::put($dir.'/shim.py', $this->shim('raw-python.py', 'action.py'));
            File::put($dir.'/drive.py', "import json\nfrom shim import dplyMain\nprint(json.dumps(dplyMain({'__ow_method': 'post', '__ow_path': '/x'})))\n");

            $result = json_decode($this->execute(['python3', 'drive.py'], $dir), true);

            $this->assertSame(201, $result['statusCode']);
            $this->assertSame('ok:post', $result['body']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_the_php_shim_executes_and_translates_the_result(): void
    {
        $this->skipUnless('php');
        $dir = $this->tempDir();

        try {
            File::put($dir.'/action.php', "<?php\nfunction main(array \$args): array {\n    return ['statusCode' => 201, 'body' => 'ok:' . \$args['__ow_method']];\n}\n");
            File::put($dir.'/shim.php', $this->shim('raw-php.php', 'action.php'));
            File::put($dir.'/drive.php', "<?php\nrequire __DIR__.'/shim.php';\necho json_encode(dplyMain(['__ow_method' => 'post', '__ow_path' => '/x']));\n");

            $result = json_decode($this->execute(['php', 'drive.php'], $dir), true);

            $this->assertSame(201, $result['statusCode']);
            $this->assertSame('ok:post', $result['body']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_the_go_shim_compiles_executes_and_translates_the_result(): void
    {
        $this->skipUnless('go');
        $dir = $this->tempDir();

        try {
            File::put($dir.'/go.mod', "module dplyshimtest\n\ngo 1.22\n");
            File::put($dir.'/action.go', "package main\n\nfunc Main(args map[string]interface{}) map[string]interface{} {\n\treturn map[string]interface{}{\"statusCode\": 201, \"body\": \"ok\"}\n}\n");
            // Inject via the real injector so the shim lands under the exact
            // filename a deploy uses (a name the Go build tool will compile).
            (new ServerlessLoggingShimInjector)->inject($dir, 'go', '');
            File::put($dir.'/drive.go', "package main\n\nimport (\n\t\"encoding/json\"\n\t\"fmt\"\n)\n\nfunc main() {\n\tresult := DplyMain(map[string]interface{}{\"__ow_method\": \"post\", \"__ow_path\": \"/x\"})\n\tencoded, _ := json.Marshal(result)\n\tfmt.Print(string(encoded))\n}\n");

            $result = json_decode($this->execute(['go', 'run', '.'], $dir), true);

            $this->assertSame(201, $result['statusCode']);
            $this->assertSame('ok', $result['body']);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
