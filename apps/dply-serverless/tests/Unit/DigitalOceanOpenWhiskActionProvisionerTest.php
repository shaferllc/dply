<?php

namespace Tests\Unit;

use App\Serverless\DigitalOcean\DigitalOceanOpenWhiskActionProvisioner;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DigitalOceanOpenWhiskActionProvisionerTest extends TestCase
{
    protected function tearDown(): void
    {
        Http::allowStrayRequests();
        parent::tearDown();
    }

    public function test_puts_action_with_base64_zip_and_basic_auth(): void
    {
        Http::fake([
            'https://faas.example.com/api/v1/namespaces/ns-1/actions/my-fn*' => Http::response(['version' => '0.0.3'], 200),
        ]);

        $dir = sys_get_temp_dir().'/do-fn-'.uniqid();
        mkdir($dir, 0777, true);
        $zip = $dir.'/bundle.zip';
        file_put_contents($zip, 'PK'.str_repeat("\0", 20));

        $p = new DigitalOceanOpenWhiskActionProvisioner(
            'https://faas.example.com',
            'ns-1',
            'dof_v1_abc:secret-val',
            $dir,
            1024 * 1024,
            'nodejs:18',
            'index.js',
            '',
        );

        $out = $p->deployFunction('my-fn', 'provided.al2023', $zip, []);

        $this->assertSame('digitalocean', $out['provider']);
        $this->assertSame('0.0.3', $out['revision_id']);
        $this->assertSame('digitalocean:function:ns-1:my-fn', $out['function_arn']);

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }
            if (! str_contains((string) $request->url(), '/api/v1/namespaces/ns-1/actions/my-fn')) {
                return false;
            }
            if (! $request->hasHeader('Authorization', 'Basic '.base64_encode('dof_v1_abc:secret-val'))) {
                return false;
            }
            $data = $request->data();
            if (($data['exec']['kind'] ?? null) !== 'nodejs:18') {
                return false;
            }
            if (($data['exec']['binary'] ?? null) !== true) {
                return false;
            }
            if (($data['exec']['main'] ?? null) !== 'index.js') {
                return false;
            }
            $code = $data['exec']['code'] ?? '';
            if (! is_string($code) || base64_decode($code, true) === false) {
                return false;
            }

            return true;
        });

        @unlink($zip);
        @rmdir($dir);
    }

    public function test_uses_package_segment_when_configured(): void
    {
        Http::fake([
            'https://faas.example.com/api/v1/namespaces/ns-1/actions/pkg/hello*' => Http::response(['version' => '1'], 200),
        ]);

        $dir = sys_get_temp_dir().'/do-pkg-'.uniqid();
        mkdir($dir, 0777, true);
        $zip = $dir.'/a.zip';
        file_put_contents($zip, 'PK'.str_repeat('x', 10));

        $p = new DigitalOceanOpenWhiskActionProvisioner(
            'https://faas.example.com',
            'ns-1',
            'u:p',
            $dir,
            1024 * 1024,
            'nodejs:18',
            'index.js',
            'pkg',
        );

        $p->deployFunction('hello', 'nodejs:20', $zip, []);

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/actions/pkg/hello'));

        @unlink($zip);
        @rmdir($dir);
    }

    public function test_uses_openwhisk_runtime_kind_when_provided(): void
    {
        Http::fake([
            'https://faas.example.com/api/v1/namespaces/ns-1/actions/x*' => Http::response(['version' => '0'], 200),
        ]);

        $dir = sys_get_temp_dir().'/do-rt-'.uniqid();
        mkdir($dir, 0777, true);
        $zip = $dir.'/x.zip';
        file_put_contents($zip, 'PK');

        $p = new DigitalOceanOpenWhiskActionProvisioner(
            'https://faas.example.com',
            'ns-1',
            'a:b',
            $dir,
            1024,
            'nodejs:18',
            'index.js',
            '',
        );

        $p->deployFunction('x', 'python:3.9', $zip, []);

        Http::assertSent(fn ($request): bool => ($request->data()['exec']['kind'] ?? null) === 'python:3.9');

        @unlink($zip);
        @rmdir($dir);
    }

    public function test_rejects_path_outside_prefix(): void
    {
        Http::fake();

        $dir = sys_get_temp_dir().'/do-in-'.uniqid();
        mkdir($dir, 0777, true);
        $outside = sys_get_temp_dir().'/do-out-'.uniqid().'.zip';
        file_put_contents($outside, 'PK');

        $p = new DigitalOceanOpenWhiskActionProvisioner(
            'https://h',
            'n',
            'a:b',
            $dir,
            1024,
            'nodejs:18',
            'index.js',
            '',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prefix');

        try {
            $p->deployFunction('fn', 'nodejs:18', $outside, []);
        } finally {
            @unlink($outside);
            @rmdir($dir);
        }
    }
}
