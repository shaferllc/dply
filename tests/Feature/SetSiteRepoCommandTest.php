<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SetSiteRepoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_url_branch_and_path(): void
    {
        $site = $this->makeSite([
            'git_repository_url' => 'git@github.com:org/old.git',
            'git_branch' => 'master',
            'repository_path' => null,
        ]);

        $exit = Artisan::call('dply:site:set-repo', [
            'site' => $site->slug,
            '--url' => 'git@github.com:org/new.git',
            '--branch' => 'main',
            '--path' => 'apps/web',
        ]);

        $this->assertSame(0, $exit);
        $site->refresh();
        $this->assertSame('git@github.com:org/new.git', $site->git_repository_url);
        $this->assertSame('main', $site->git_branch);
        $this->assertSame('apps/web', $site->repository_path);
    }

    public function test_accepts_https_urls(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:set-repo', [
            'site' => $site->slug,
            '--url' => 'https://github.com/org/repo.git',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('https://github.com/org/repo.git', $site->fresh()->git_repository_url);
    }

    public function test_rejects_garbage_url(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:set-repo', [
            'site' => $site->slug,
            '--url' => 'not a url',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not look like a git repo', $output);
    }

    public function test_strips_leading_and_trailing_slashes_from_path(): void
    {
        $site = $this->makeSite();

        Artisan::call('dply:site:set-repo', [
            'site' => $site->slug,
            '--path' => '/apps/web/',
        ]);

        $this->assertSame('apps/web', $site->fresh()->repository_path);
    }

    public function test_empty_path_clears_path_field(): void
    {
        $site = $this->makeSite(['repository_path' => 'old/path']);

        Artisan::call('dply:site:set-repo', [
            'site' => $site->slug,
            '--path' => '',
        ]);

        $this->assertNull($site->fresh()->repository_path);
    }

    public function test_empty_branch_is_rejected(): void
    {
        $site = $this->makeSite(['git_branch' => 'main']);

        $exit = Artisan::call('dply:site:set-repo', [
            'site' => $site->slug,
            '--branch' => '',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cannot be empty', $output);
        $this->assertSame('main', $site->fresh()->git_branch);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $site = $this->makeSite(['git_branch' => 'master']);

        Artisan::call('dply:site:set-repo', [
            'site' => $site->slug,
            '--branch' => 'main',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['dry_run']);
        $this->assertSame('master', $site->fresh()->git_branch);
    }

    public function test_fails_when_no_options_given(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:set-repo', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Pass at least one', $output);
    }

    public function test_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:set-repo', [
            'site' => 'nope',
            '--branch' => 'main',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeSite(array $attrs = []): Site
    {
        $server = Server::factory()->create();

        return Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'slug' => 'jobs',
        ], $attrs));
    }
}
