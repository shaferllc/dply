<?php

namespace Tests\Unit;

use App\Serverless\Support\ArtifactZipPathPrefix;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ArtifactZipPathPrefixTest extends TestCase
{
    public function test_returns_global_when_setting_absent(): void
    {
        $dir = sys_get_temp_dir().'/azp-global-'.uniqid();
        mkdir($dir, 0777, true);

        $resolved = ArtifactZipPathPrefix::resolve($dir, [], 'netlify_deploy_zip_path_prefix');

        $this->assertSame(realpath($dir), $resolved);

        @rmdir($dir);
    }

    public function test_returns_subdirectory_when_valid(): void
    {
        $base = sys_get_temp_dir().'/azp-base-'.uniqid();
        $sub = $base.'/tenant-a';
        mkdir($sub, 0777, true);

        $resolved = ArtifactZipPathPrefix::resolve($base, [
            'project' => ['settings' => ['netlify_deploy_zip_path_prefix' => $sub]],
        ], 'netlify_deploy_zip_path_prefix');

        $this->assertSame(realpath($sub), $resolved);

        @rmdir($sub);
        @rmdir($base);
    }

    public function test_rejects_prefix_outside_global(): void
    {
        $base = sys_get_temp_dir().'/azp-in-'.uniqid();
        mkdir($base, 0777, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('subdirectory');

        try {
            ArtifactZipPathPrefix::resolve($base, [
                'project' => ['settings' => ['netlify_deploy_zip_path_prefix' => '/etc']],
            ], 'netlify_deploy_zip_path_prefix');
        } finally {
            @rmdir($base);
        }
    }

    public function test_rejects_non_resolvable_project_path(): void
    {
        $base = sys_get_temp_dir().'/azp-miss-'.uniqid();
        mkdir($base, 0777, true);
        $missing = $base.'/does-not-exist';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not resolvable');

        try {
            ArtifactZipPathPrefix::resolve($base, [
                'project' => ['settings' => ['netlify_deploy_zip_path_prefix' => $missing]],
            ], 'netlify_deploy_zip_path_prefix');
        } finally {
            @rmdir($base);
        }
    }
}
