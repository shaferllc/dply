<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RemoveSiteDomainCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_removes_a_non_primary_domain(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'primary.example.com', 'is_primary' => true, 'www_redirect' => false]);
        $site->domains()->create(['hostname' => 'extra.example.com', 'is_primary' => false, 'www_redirect' => false]);

        $exit = Artisan::call('dply:site:domain-remove', [
            'site' => $site->id,
            'hostname' => 'extra.example.com',
        ]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('site_domains', [
            'site_id' => $site->id,
            'hostname' => 'extra.example.com',
        ]);
    }

    public function test_refuses_to_remove_only_remaining_domain_without_force(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'only.example.com', 'is_primary' => true, 'www_redirect' => false]);

        $exit = Artisan::call('dply:site:domain-remove', [
            'site' => $site->id,
            'hostname' => 'only.example.com',
        ]);

        $this->assertSame(1, $exit);
        $this->assertDatabaseHas('site_domains', [
            'site_id' => $site->id,
            'hostname' => 'only.example.com',
        ]);
    }

    public function test_force_overrides_only_domain_refusal(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'only.example.com', 'is_primary' => true, 'www_redirect' => false]);

        $exit = Artisan::call('dply:site:domain-remove', [
            'site' => $site->id,
            'hostname' => 'only.example.com',
            '--force' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('site_domains', [
            'site_id' => $site->id,
            'hostname' => 'only.example.com',
        ]);
    }

    public function test_refuses_to_remove_primary_when_other_domains_exist(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'primary.example.com', 'is_primary' => true, 'www_redirect' => false]);
        $site->domains()->create(['hostname' => 'extra.example.com', 'is_primary' => false, 'www_redirect' => false]);

        $exit = Artisan::call('dply:site:domain-remove', [
            'site' => $site->id,
            'hostname' => 'primary.example.com',
        ]);

        $this->assertSame(1, $exit);
        $this->assertDatabaseHas('site_domains', [
            'site_id' => $site->id,
            'hostname' => 'primary.example.com',
        ]);
    }

    public function test_unknown_hostname_returns_failure(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'a.example.com', 'is_primary' => true, 'www_redirect' => false]);

        $exit = Artisan::call('dply:site:domain-remove', [
            'site' => $site->id,
            'hostname' => 'nope.example.com',
        ]);

        $this->assertSame(1, $exit);
    }

    public function test_json_output_contains_removed_hostname(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'primary.example.com', 'is_primary' => true, 'www_redirect' => false]);
        $site->domains()->create(['hostname' => 'extra.example.com', 'is_primary' => false, 'www_redirect' => false]);

        Artisan::call('dply:site:domain-remove', [
            'site' => $site->id,
            'hostname' => 'extra.example.com',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('extra.example.com', $payload['removed']);
        $this->assertSame($site->id, $payload['site_id']);
    }

    private function makeSite(): Site
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
        ]);
    }
}
