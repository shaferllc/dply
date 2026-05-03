<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AddSiteDomainCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_adds_a_non_primary_domain(): void
    {
        $site = $this->makeSite('shop');

        $exit = Artisan::call('dply:site:domain-add', [
            'site' => $site->id,
            'hostname' => 'shop.example.com',
        ]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('site_domains', [
            'site_id' => $site->id,
            'hostname' => 'shop.example.com',
            'is_primary' => false,
        ]);
    }

    public function test_normalizes_hostname_strips_scheme_and_lowercases(): void
    {
        $site = $this->makeSite('shop');

        Artisan::call('dply:site:domain-add', [
            'site' => $site->id,
            'hostname' => 'HTTPS://Shop.Example.com/',
        ]);

        $this->assertDatabaseHas('site_domains', [
            'site_id' => $site->id,
            'hostname' => 'shop.example.com',
        ]);
    }

    public function test_primary_flag_clears_other_domains_primary(): void
    {
        $site = $this->makeSite('shop');
        $site->domains()->create([
            'hostname' => 'old.example.com',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        Artisan::call('dply:site:domain-add', [
            'site' => $site->id,
            'hostname' => 'new.example.com',
            '--primary' => true,
        ]);

        $this->assertDatabaseHas('site_domains', [
            'site_id' => $site->id,
            'hostname' => 'new.example.com',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('site_domains', [
            'site_id' => $site->id,
            'hostname' => 'old.example.com',
            'is_primary' => false,
        ]);
    }

    public function test_refuses_duplicate_hostname_on_same_site(): void
    {
        $site = $this->makeSite('shop');
        $site->domains()->create([
            'hostname' => 'shop.example.com',
            'is_primary' => false,
            'www_redirect' => false,
        ]);

        $exit = Artisan::call('dply:site:domain-add', [
            'site' => $site->id,
            'hostname' => 'shop.example.com',
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame(
            1,
            $site->domains()->where('hostname', 'shop.example.com')->count(),
        );
    }

    public function test_invalid_hostname_is_rejected(): void
    {
        $site = $this->makeSite('shop');

        $exit = Artisan::call('dply:site:domain-add', [
            'site' => $site->id,
            'hostname' => 'no-tld',
        ]);

        $this->assertSame(1, $exit);
        $this->assertDatabaseMissing('site_domains', [
            'site_id' => $site->id,
            'hostname' => 'no-tld',
        ]);
    }

    public function test_unknown_site_returns_failure(): void
    {
        $exit = Artisan::call('dply:site:domain-add', [
            'site' => 'no-such-site',
            'hostname' => 'a.example.com',
        ]);

        $this->assertSame(1, $exit);
    }

    public function test_json_output_includes_new_domain(): void
    {
        $site = $this->makeSite('shop');

        Artisan::call('dply:site:domain-add', [
            'site' => $site->slug,
            'hostname' => 'shop.example.com',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame($site->id, $payload['site_id']);
        $this->assertSame('shop.example.com', $payload['domain']['hostname']);
        $this->assertFalse($payload['domain']['is_primary']);
    }

    private function makeSite(string $slug): Site
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'slug' => $slug,
        ]);
    }
}
