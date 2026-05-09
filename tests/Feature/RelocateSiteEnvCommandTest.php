<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PushSiteEnvJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RelocateSiteEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_path_uses_etc_dply_convention(): void
    {
        Queue::fake();
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-relocate', [
            'site' => $site->slug,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('/etc/dply/'.$site->slug.'.env', $site->fresh()->env_file_path);
        Queue::assertPushed(PushSiteEnvJob::class, fn ($job) => $job->siteId === $site->id);
    }

    public function test_custom_path_with_to_option(): void
    {
        Queue::fake();
        $site = $this->makeSite();

        Artisan::call('dply:site:env-relocate', [
            'site' => $site->slug,
            '--to' => '/srv/secrets/jobs.env',
        ]);

        $this->assertSame('/srv/secrets/jobs.env', $site->fresh()->env_file_path);
    }

    public function test_reset_clears_override_and_does_not_dispatch(): void
    {
        Queue::fake();
        $site = $this->makeSite(['env_file_path' => '/etc/dply/jobs.env']);

        Artisan::call('dply:site:env-relocate', [
            'site' => $site->slug,
            '--reset' => true,
        ]);

        $this->assertNull($site->fresh()->env_file_path);
        Queue::assertNotPushed(PushSiteEnvJob::class);
    }

    public function test_no_push_flag_skips_job_dispatch(): void
    {
        Queue::fake();
        $site = $this->makeSite();

        Artisan::call('dply:site:env-relocate', [
            'site' => $site->slug,
            '--to' => '/etc/dply/foo.env',
            '--no-push' => true,
        ]);

        $this->assertSame('/etc/dply/foo.env', $site->fresh()->env_file_path);
        Queue::assertNotPushed(PushSiteEnvJob::class);
    }

    public function test_rejects_relative_path(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-relocate', [
            'site' => $site->slug,
            '--to' => 'etc/dply/jobs.env',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('absolute path', $output);
        $this->assertNull($site->fresh()->env_file_path);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:env-relocate', ['site' => 'nope']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeSite(array $attrs = []): Site
    {
        $server = Server::factory()->ready()->create();

        return Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'slug' => 'jobs',
        ], $attrs));
    }
}
