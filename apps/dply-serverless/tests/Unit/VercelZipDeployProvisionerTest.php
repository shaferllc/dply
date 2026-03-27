<?php

namespace Tests\Unit;

use App\Serverless\Vercel\VercelZipDeployProvisioner;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class VercelZipDeployProvisionerTest extends TestCase
{
    protected function tearDown(): void
    {
        Http::allowStrayRequests();
        parent::tearDown();
    }

    public function test_posts_deployment_with_files_from_zip(): void
    {
        Http::fake([
            'api.vercel.com/*' => Http::response([
                'id' => 'dpl_abc123',
                'url' => 'https://example.vercel.app',
            ], 200),
        ]);

        $dir = sys_get_temp_dir().'/vc-deploy-'.uniqid();
        mkdir($dir, 0777, true);
        $zip = $dir.'/bundle.zip';
        $this->createZip($zip, [
            'index.html' => '<html></html>',
            'api/hello.js' => 'export default function handler() {}',
        ]);

        $p = new VercelZipDeployProvisioner(
            'vc-token',
            '',
            '',
            'my-vercel-project',
            $dir,
            1024 * 1024,
            2000,
            50 * 1024 * 1024,
        );

        $out = $p->deployFunction('fn', 'vercel', $zip, []);

        $this->assertSame('vercel', $out['provider']);
        $this->assertSame('dpl_abc123', $out['revision_id']);
        $this->assertSame('vercel:deployment:dpl_abc123', $out['function_arn']);

        Http::assertSent(function ($request): bool {
            if (! str_contains((string) $request->url(), 'api.vercel.com/v13/deployments')) {
                return false;
            }
            if (! $request->hasHeader('Authorization', 'Bearer vc-token')) {
                return false;
            }
            $data = $request->data();
            if (($data['name'] ?? null) !== 'my-vercel-project') {
                return false;
            }
            $files = $data['files'] ?? [];
            if (! is_array($files) || count($files) !== 2) {
                return false;
            }

            return true;
        });

        @unlink($zip);
        @rmdir($dir);
    }

    public function test_uses_project_field_when_project_id_in_config(): void
    {
        Http::fake([
            'api.vercel.com/*' => Http::response(['id' => 'dpl_x'], 200),
        ]);

        $dir = sys_get_temp_dir().'/vc-proj-'.uniqid();
        mkdir($dir, 0777, true);
        $zip = $dir.'/b.zip';
        $this->createZip($zip, ['a.txt' => 'hi']);

        $p = new VercelZipDeployProvisioner(
            't',
            '',
            '',
            'ignored-name',
            $dir,
            1024 * 1024,
            2000,
            50 * 1024 * 1024,
        );

        $p->deployFunction('fn', 'vercel', $zip, [
            'credentials' => [
                'vercel_token' => 'proj-tok',
                'vercel_project_id' => 'prj_12345',
                'vercel_team_id' => 'team_abc',
            ],
        ]);

        Http::assertSent(function ($request): bool {
            $u = (string) $request->url();

            return str_contains($u, 'teamId=team_abc')
                && $request->hasHeader('Authorization', 'Bearer proj-tok')
                && (($request->data()['project'] ?? null) === 'prj_12345')
                && ! array_key_exists('name', $request->data());
        });

        @unlink($zip);
        @rmdir($dir);
    }

    public function test_skips_macosx_entries(): void
    {
        Http::fake([
            'api.vercel.com/*' => Http::response(['id' => 'dpl_y'], 200),
        ]);

        $dir = sys_get_temp_dir().'/vc-mac-'.uniqid();
        mkdir($dir, 0777, true);
        $zip = $dir.'/m.zip';
        $this->createZip($zip, [
            'app.js' => 'x',
            '__MACOSX/._app.js' => 'garbage',
        ]);

        $p = new VercelZipDeployProvisioner('t', '', '', 'p', $dir, 1024 * 1024, 2000, 50 * 1024 * 1024);
        $p->deployFunction('fn', 'vercel', $zip, []);

        Http::assertSent(function ($request): bool {
            $files = $request->data()['files'] ?? [];

            return is_array($files) && count($files) === 1 && $files[0]['file'] === 'app.js';
        });

        @unlink($zip);
        @rmdir($dir);
    }

    public function test_allows_zip_under_project_vercel_sub_prefix(): void
    {
        Http::fake([
            'api.vercel.com/*' => Http::response(['id' => 'dpl_sub'], 200),
        ]);

        $base = sys_get_temp_dir().'/vc-sub-'.uniqid();
        $tenant = $base.'/tenant';
        mkdir($tenant, 0777, true);
        $zip = $tenant.'/b.zip';
        $this->createZip($zip, ['index.html' => 'ok']);

        $p = new VercelZipDeployProvisioner(
            't',
            '',
            '',
            'proj',
            $base,
            1024 * 1024,
            2000,
            50 * 1024 * 1024,
        );

        $p->deployFunction('fn', 'vercel', $zip, [
            'project' => ['settings' => ['vercel_deploy_zip_path_prefix' => $tenant]],
        ]);

        Http::assertSentCount(1);

        @unlink($zip);
        @rmdir($tenant);
        @rmdir($base);
    }

    public function test_rejects_zip_under_global_but_not_under_project_vercel_sub_prefix(): void
    {
        Http::fake();

        $base = sys_get_temp_dir().'/vc-sib-'.uniqid();
        $allowed = $base.'/a';
        $sibling = $base.'/b';
        mkdir($allowed, 0777, true);
        mkdir($sibling, 0777, true);
        $zip = $sibling.'/x.zip';
        $this->createZip($zip, ['i.html' => 'x']);

        $p = new VercelZipDeployProvisioner('t', '', '', 'p', $base, 1024 * 1024, 2000, 50 * 1024 * 1024);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes allowed prefix');

        try {
            $p->deployFunction('fn', 'vercel', $zip, [
                'project' => ['settings' => ['vercel_deploy_zip_path_prefix' => $allowed]],
            ]);
        } finally {
            @unlink($zip);
            @rmdir($sibling);
            @rmdir($allowed);
            @rmdir($base);
        }
    }

    public function test_rejects_non_zip(): void
    {
        Http::fake();

        $dir = sys_get_temp_dir().'/vc-bad-'.uniqid();
        mkdir($dir, 0777, true);
        $path = $dir.'/nope.txt';
        file_put_contents($path, 'x');

        $p = new VercelZipDeployProvisioner('t', '', '', 'p', $dir, 1024, 10, 1024);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('.zip');

        try {
            $p->deployFunction('fn', 'vercel', $path, []);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function test_rejects_path_outside_prefix(): void
    {
        Http::fake();

        $dir = sys_get_temp_dir().'/vc-safe-'.uniqid();
        mkdir($dir, 0777, true);
        $outside = sys_get_temp_dir().'/vc-out-'.uniqid().'.zip';
        file_put_contents($outside, 'PK');

        $p = new VercelZipDeployProvisioner('t', '', '', 'p', $dir, 1024, 10, 1024);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prefix');

        try {
            $p->deployFunction('fn', 'vercel', $outside, []);
        } finally {
            @unlink($outside);
            @rmdir($dir);
        }
    }

    /**
     * @param  array<string, string>  $pathsToContents
     */
    private function createZip(string $zipPath, array $pathsToContents): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($pathsToContents as $path => $content) {
            $this->assertTrue($zip->addFromString($path, $content));
        }
        $this->assertTrue($zip->close());
    }
}
