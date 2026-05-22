<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SiteDomainCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_creates_a_domain_row(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:domain-add', [
            'site' => $site->slug,
            'hostname' => 'jobs.example.com',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('jobs.example.com', $decoded['domain']['hostname']);
        $this->assertFalse($decoded['domain']['is_primary']);
        $this->assertSame(1, $site->domains()->count());
    }

    public function test_add_normalizes_scheme_and_case(): void
    {
        $site = $this->makeSite();

        Artisan::call('dply:site:domain-add', [
            'site' => $site->slug,
            'hostname' => 'HTTPS://Example.COM/',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('example.com', $decoded['domain']['hostname']);
    }

    public function test_add_primary_clears_flag_on_other_domains(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'old.example.com', 'is_primary' => true]);

        Artisan::call('dply:site:domain-add', [
            'site' => $site->slug,
            'hostname' => 'new.example.com',
            '--primary' => true,
        ]);

        $primaries = $site->domains()->where('is_primary', true)->get();
        $this->assertCount(1, $primaries);
        $this->assertSame('new.example.com', $primaries->first()->hostname);
    }

    public function test_add_rejects_duplicate_on_same_site(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'jobs.example.com']);

        $exit = Artisan::call('dply:site:domain-add', [
            'site' => $site->slug,
            'hostname' => 'jobs.example.com',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('already exists', $output);
    }

    public function test_add_rejects_invalid_hostname(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:domain-add', [
            'site' => $site->slug,
            'hostname' => 'not a hostname',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not look valid', $output);
    }

    public function test_remove_deletes_domain(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'a.example.com']);
        $site->domains()->create(['hostname' => 'b.example.com']);

        $exit = Artisan::call('dply:site:domain-remove', [
            'site' => $site->slug,
            'hostname' => 'a.example.com',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('a.example.com', $decoded['removed']);
        $this->assertSame(1, $site->domains()->count());
    }

    public function test_remove_refuses_only_domain_without_force(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'only.example.com']);

        $exit = Artisan::call('dply:site:domain-remove', [
            'site' => $site->slug,
            'hostname' => 'only.example.com',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('only domain', $output);
        $this->assertSame(1, $site->domains()->count());
    }

    public function test_remove_force_overrides_only_domain_check(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'only.example.com']);

        $exit = Artisan::call('dply:site:domain-remove', [
            'site' => $site->slug,
            'hostname' => 'only.example.com',
            '--force' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $site->domains()->count());
    }

    public function test_remove_refuses_primary_when_others_exist(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'primary.example.com', 'is_primary' => true]);
        $site->domains()->create(['hostname' => 'alias.example.com', 'is_primary' => false]);

        $exit = Artisan::call('dply:site:domain-remove', [
            'site' => $site->slug,
            'hostname' => 'primary.example.com',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('primary domain', $output);
    }

    public function test_remove_fails_when_hostname_not_found(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:domain-remove', [
            'site' => $site->slug,
            'hostname' => 'missing.example.com',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Domain not found', $output);
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
