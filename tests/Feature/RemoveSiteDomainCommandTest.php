<?php

declare(strict_types=1);

namespace Tests\Feature\RemoveSiteDomainCommandTest;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('removes a non primary domain', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'primary.example.com', 'is_primary' => true, 'www_redirect' => false]);
    $site->domains()->create(['hostname' => 'extra.example.com', 'is_primary' => false, 'www_redirect' => false]);

    $exit = Artisan::call('dply:site:domain-remove', [
        'site' => $site->id,
        'hostname' => 'extra.example.com',
    ]);

    expect($exit)->toBe(0);
    $this->assertDatabaseMissing('site_domains', [
        'site_id' => $site->id,
        'hostname' => 'extra.example.com',
    ]);
});
test('refuses to remove only remaining domain without force', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'only.example.com', 'is_primary' => true, 'www_redirect' => false]);

    $exit = Artisan::call('dply:site:domain-remove', [
        'site' => $site->id,
        'hostname' => 'only.example.com',
    ]);

    expect($exit)->toBe(1);
    $this->assertDatabaseHas('site_domains', [
        'site_id' => $site->id,
        'hostname' => 'only.example.com',
    ]);
});
test('force overrides only domain refusal', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'only.example.com', 'is_primary' => true, 'www_redirect' => false]);

    $exit = Artisan::call('dply:site:domain-remove', [
        'site' => $site->id,
        'hostname' => 'only.example.com',
        '--force' => true,
    ]);

    expect($exit)->toBe(0);
    $this->assertDatabaseMissing('site_domains', [
        'site_id' => $site->id,
        'hostname' => 'only.example.com',
    ]);
});
test('refuses to remove primary when other domains exist', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'primary.example.com', 'is_primary' => true, 'www_redirect' => false]);
    $site->domains()->create(['hostname' => 'extra.example.com', 'is_primary' => false, 'www_redirect' => false]);

    $exit = Artisan::call('dply:site:domain-remove', [
        'site' => $site->id,
        'hostname' => 'primary.example.com',
    ]);

    expect($exit)->toBe(1);
    $this->assertDatabaseHas('site_domains', [
        'site_id' => $site->id,
        'hostname' => 'primary.example.com',
    ]);
});
test('unknown hostname returns failure', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'a.example.com', 'is_primary' => true, 'www_redirect' => false]);

    $exit = Artisan::call('dply:site:domain-remove', [
        'site' => $site->id,
        'hostname' => 'nope.example.com',
    ]);

    expect($exit)->toBe(1);
});
test('json output contains removed hostname', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'primary.example.com', 'is_primary' => true, 'www_redirect' => false]);
    $site->domains()->create(['hostname' => 'extra.example.com', 'is_primary' => false, 'www_redirect' => false]);

    Artisan::call('dply:site:domain-remove', [
        'site' => $site->id,
        'hostname' => 'extra.example.com',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true);
    expect($payload)->toBeArray();
    expect($payload['removed'])->toBe('extra.example.com');
    expect($payload['site_id'])->toBe($site->id);
});
function makeSite(): Site
{
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
    ]);
}
