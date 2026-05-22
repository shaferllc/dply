<?php

declare(strict_types=1);

namespace Tests\Feature\AddSiteDomainCommandTest;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('adds a non primary domain', function () {
    $site = makeSite('shop');

    $exit = Artisan::call('dply:site:domain-add', [
        'site' => $site->id,
        'hostname' => 'shop.example.com',
    ]);

    expect($exit)->toBe(0);
    $this->assertDatabaseHas('site_domains', [
        'site_id' => $site->id,
        'hostname' => 'shop.example.com',
        'is_primary' => false,
    ]);
});
test('normalizes hostname strips scheme and lowercases', function () {
    $site = makeSite('shop');

    Artisan::call('dply:site:domain-add', [
        'site' => $site->id,
        'hostname' => 'HTTPS://Shop.Example.com/',
    ]);

    $this->assertDatabaseHas('site_domains', [
        'site_id' => $site->id,
        'hostname' => 'shop.example.com',
    ]);
});
test('primary flag clears other domains primary', function () {
    $site = makeSite('shop');
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
});
test('refuses duplicate hostname on same site', function () {
    $site = makeSite('shop');
    $site->domains()->create([
        'hostname' => 'shop.example.com',
        'is_primary' => false,
        'www_redirect' => false,
    ]);

    $exit = Artisan::call('dply:site:domain-add', [
        'site' => $site->id,
        'hostname' => 'shop.example.com',
    ]);

    expect($exit)->toBe(1);
    expect($site->domains()->where('hostname', 'shop.example.com')->count())->toBe(1);
});
test('invalid hostname is rejected', function () {
    $site = makeSite('shop');

    $exit = Artisan::call('dply:site:domain-add', [
        'site' => $site->id,
        'hostname' => 'no-tld',
    ]);

    expect($exit)->toBe(1);
    $this->assertDatabaseMissing('site_domains', [
        'site_id' => $site->id,
        'hostname' => 'no-tld',
    ]);
});
test('unknown site returns failure', function () {
    $exit = Artisan::call('dply:site:domain-add', [
        'site' => 'no-such-site',
        'hostname' => 'a.example.com',
    ]);

    expect($exit)->toBe(1);
});
test('json output includes new domain', function () {
    $site = makeSite('shop');

    Artisan::call('dply:site:domain-add', [
        'site' => $site->slug,
        'hostname' => 'shop.example.com',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true);
    expect($payload)->toBeArray();
    expect($payload['site_id'])->toBe($site->id);
    expect($payload['domain']['hostname'])->toBe('shop.example.com');
    expect($payload['domain']['is_primary'])->toBeFalse();
});
function makeSite(string $slug): Site
{
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'slug' => $slug,
    ]);
}
