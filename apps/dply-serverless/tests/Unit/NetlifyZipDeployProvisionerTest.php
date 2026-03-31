<?php

namespace Tests\Unit;

use App\Serverless\Netlify\NetlifyZipDeployProvisioner;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NetlifyZipDeployProvisionerTest extends TestCase
{
    protected function tearDown(): void
    {
        Http::allowStrayRequests();
        parent::tearDown();
    }

    public function test_posts_zip_to_netlify_deploys_endpoint(): void
    {
        Http::fake([
            'api.netlify.com/*' => Http::response([
                'id' => 'deploy-abc',
                'state' => 'building',
            ], 201),
        ]);

        $dir = sys_get_temp_dir().'/nl-deploy-'.uniqid();
        mkdir($dir, 0777, true);
        $zip = $dir.'/site.zip';
        file_put_contents($zip, 'PK'.str_repeat("\0", 20));

        $p = new NetlifyZipDeployProvisioner(
            'default-token',
            'site-default',
            $dir,
            1024 * 1024,
        );

        $out = $p->deployFunction('my-site', 'netlify', $zip, []);

        $this->assertSame('netlify', $out['provider']);
        $this->assertSame('deploy-abc', $out['revision_id']);
        $this->assertSame('netlify:site:site-default:deploy:deploy-abc', $out['function_arn']);

        Http::assertSent(function ($request): bool {
            return str_contains((string) $request->url(), '/api/v1/sites/site-default/deploys')
                && $request->hasHeader('Authorization', 'Bearer default-token');
        });

        @unlink($zip);
        @rmdir($dir);
    }

    public function test_uses_project_credentials_when_provided(): void
    {
        Http::fake([
            'api.netlify.com/*' => Http::response(['id' => 'd2', 'state' => 'new'], 201),
        ]);

        $dir = sys_get_temp_dir().'/nl-proj-'.uniqid();
        mkdir($dir, 0777, true);
        $zip = $dir.'/bundle.zip';
        file_put_contents($zip, 'PK'.str_repeat('x', 10));

        $p = new NetlifyZipDeployProvisioner(
            'ignored',
            'ignored',
            $dir,
            1024 * 1024,
        );

        $p->deployFunction('fn', 'netlify', $zip, [
            'credentials' => [
                'api_token' => 'proj-token',
                'site_id' => 'proj-site-uuid',
            ],
        ]);

        Http::assertSent(function ($request): bool {
            return str_contains((string) $request->url(), '/api/v1/sites/proj-site-uuid/deploys')
                && $request->hasHeader('Authorization', 'Bearer proj-token');
        });

        @unlink($zip);
        @rmdir($dir);
    }

    public function test_rejects_non_zip_extension(): void
    {
        Http::fake();

        $dir = sys_get_temp_dir().'/nl-badext-'.uniqid();
        mkdir($dir, 0777, true);
        $path = $dir.'/not-a-zip.txt';
        file_put_contents($path, 'x');

        $p = new NetlifyZipDeployProvisioner('t', 's', $dir, 1024);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('.zip');

        try {
            $p->deployFunction('fn', 'netlify', $path, []);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function test_allows_zip_only_under_project_sub_prefix_when_configured(): void
    {
        Http::fake([
            'api.netlify.com/*' => Http::response(['id' => 'd-sub', 'state' => 'new'], 201),
        ]);

        $base = sys_get_temp_dir().'/nl-sub-'.uniqid();
        $tenant = $base.'/t1';
        mkdir($tenant, 0777, true);
        $zip = $tenant.'/app.zip';
        file_put_contents($zip, 'PK'.str_repeat('x', 10));

        $p = new NetlifyZipDeployProvisioner(
            't',
            'site-x',
            $base,
            1024 * 1024,
        );

        $p->deployFunction('fn', 'netlify', $zip, [
            'project' => ['settings' => ['netlify_deploy_zip_path_prefix' => $tenant]],
        ]);

        Http::assertSentCount(1);

        @unlink($zip);
        @rmdir($tenant);
        @rmdir($base);
    }

    public function test_rejects_zip_under_global_but_not_under_project_sub_prefix(): void
    {
        Http::fake();

        $base = sys_get_temp_dir().'/nl-sib-'.uniqid();
        $allowed = $base.'/tenant-a';
        $sibling = $base.'/tenant-b';
        mkdir($allowed, 0777, true);
        mkdir($sibling, 0777, true);
        $zip = $sibling.'/other.zip';
        file_put_contents($zip, 'PK'.str_repeat('x', 10));

        $p = new NetlifyZipDeployProvisioner('t', 's', $base, 1024 * 1024);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes allowed prefix');

        try {
            $p->deployFunction('fn', 'netlify', $zip, [
                'project' => ['settings' => ['netlify_deploy_zip_path_prefix' => $allowed]],
            ]);
        } finally {
            @unlink($zip);
            @rmdir($sibling);
            @rmdir($allowed);
            @rmdir($base);
        }
    }

    public function test_rejects_path_outside_prefix(): void
    {
        Http::fake();

        $dir = sys_get_temp_dir().'/nl-safe-'.uniqid();
        mkdir($dir, 0777, true);
        $outside = sys_get_temp_dir().'/nl-evil-'.uniqid().'.zip';
        file_put_contents($outside, 'PK');

        $p = new NetlifyZipDeployProvisioner('t', 's', $dir, 1024);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prefix');

        try {
            $p->deployFunction('fn', 'netlify', $outside, []);
        } finally {
            @unlink($outside);
            @rmdir($dir);
        }
    }
}
