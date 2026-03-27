<?php

namespace Tests\Unit;

use App\Serverless\Cloudflare\CloudflareWorkersProvisioner;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CloudflareWorkersProvisionerTest extends TestCase
{
    protected function tearDown(): void
    {
        Http::allowStrayRequests();
        parent::tearDown();
    }

    public function test_uploads_script_via_multipart_api(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['id' => 'script-id-1'],
            ], 200, ['ETag' => '"etag-1"']),
        ]);

        $dir = sys_get_temp_dir().'/cf-worker-test-'.uniqid();
        mkdir($dir, 0777, true);
        $path = $dir.'/hello.js';
        file_put_contents($path, 'export default { async fetch() { return new Response("ok"); } };');

        $p = new CloudflareWorkersProvisioner(
            'acct-1',
            'api-token',
            '2024-11-01',
            $dir,
            1024 * 1024,
        );

        $out = $p->deployFunction('MyWorker', 'cloudflare-workers', $path, []);

        $this->assertSame('cloudflare', $out['provider']);
        $this->assertSame('cloudflare:worker:acct-1:myworker', $out['function_arn']);
        $this->assertSame('etag-1', $out['revision_id']);

        Http::assertSentCount(1);
        @unlink($path);
        @rmdir($dir);
    }

    public function test_uses_project_credentials_when_provided(): void
    {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['id' => 'script-id-1'],
            ], 200, ['ETag' => '"etag-2"']),
        ]);

        $dir = sys_get_temp_dir().'/cf-worker-override-'.uniqid();
        mkdir($dir, 0777, true);
        $path = $dir.'/hello.js';
        file_put_contents($path, 'export default { async fetch() { return new Response("ok"); } };');

        $p = new CloudflareWorkersProvisioner(
            'default-acct',
            'default-token',
            '2024-11-01',
            $dir,
            1024 * 1024,
        );

        $p->deployFunction('OvWorker', 'cloudflare-workers', $path, [
            'credentials' => [
                'account_id' => 'proj-acct',
                'api_token' => 'proj-token',
            ],
            'project' => [
                'settings' => [
                    'cloudflare_compatibility_date' => '2025-01-01',
                ],
            ],
        ]);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/accounts/proj-acct/workers/scripts/')
                && $request->hasHeader('Authorization', 'Bearer proj-token');
        });

        @unlink($path);
        @rmdir($dir);
    }

    public function test_rejects_path_outside_prefix(): void
    {
        Http::fake();

        $dir = sys_get_temp_dir().'/cf-worker-safe-'.uniqid();
        mkdir($dir, 0777, true);
        $outside = sys_get_temp_dir().'/cf-worker-evil-'.uniqid().'.js';
        file_put_contents($outside, 'x');

        $p = new CloudflareWorkersProvisioner(
            'acct',
            'tok',
            '2024-11-01',
            $dir,
            1024,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prefix');

        try {
            $p->deployFunction('w', 'rt', $outside, []);
        } finally {
            @unlink($outside);
            @rmdir($dir);
        }
    }

    public function test_rejects_oversized_file(): void
    {
        Http::fake();

        $dir = sys_get_temp_dir().'/cf-worker-big-'.uniqid();
        mkdir($dir, 0777, true);
        $path = $dir.'/big.js';
        file_put_contents($path, str_repeat('a', 100));

        $p = new CloudflareWorkersProvisioner(
            'acct',
            'tok',
            '2024-11-01',
            $dir,
            10,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('maximum size');

        try {
            $p->deployFunction('w', 'rt', $path, []);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }
}
