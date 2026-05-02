<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SiteUrlCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prints_primary_url_with_https_by_default(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'alias.example.com', 'is_primary' => false]);
        $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

        $exit = Artisan::call('dply:site:url', ['site' => $site->slug]);
        $output = trim(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('https://jobs.example.com', $output);
    }

    public function test_scheme_option_changes_protocol(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

        Artisan::call('dply:site:url', [
            'site' => $site->slug,
            '--scheme' => 'http',
        ]);
        $output = trim(Artisan::output());

        $this->assertSame('http://jobs.example.com', $output);
    }

    public function test_all_flag_prints_every_domain_primary_first(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'b.example.com', 'is_primary' => false]);
        $site->domains()->create(['hostname' => 'a.example.com', 'is_primary' => true]);

        Artisan::call('dply:site:url', [
            'site' => $site->slug,
            '--all' => true,
        ]);
        $lines = array_values(array_filter(explode("\n", Artisan::output())));

        $this->assertSame([
            'https://a.example.com',
            'https://b.example.com',
        ], $lines);
    }

    public function test_json_output_includes_all_urls(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

        Artisan::call('dply:site:url', [
            'site' => $site->slug,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('https', $decoded['scheme']);
        $this->assertSame(['https://jobs.example.com'], $decoded['urls']);
    }

    public function test_exits_non_zero_when_no_domains(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:url', ['site' => $site->slug]);

        $this->assertSame(1, $exit);
    }

    public function test_rejects_invalid_scheme(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:url', [
            'site' => $site->slug,
            '--scheme' => 'ftp',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid --scheme', $output);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:url', ['site' => 'nope']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    private function makeSite(): Site
    {
        $server = Server::factory()->create();

        return Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'jobs',
        ]);
    }
}
