<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDomainCommandsTest;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('add creates a domain row', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:domain-add', [
        'site' => $site->slug,
        'hostname' => 'jobs.example.com',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($decoded['domain']['hostname'])->toBe('jobs.example.com');
    expect($decoded['domain']['is_primary'])->toBeFalse();
    expect($site->domains()->count())->toBe(1);
});
test('add normalizes scheme and case', function () {
    $site = makeSite();

    Artisan::call('dply:site:domain-add', [
        'site' => $site->slug,
        'hostname' => 'HTTPS://Example.COM/',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['domain']['hostname'])->toBe('example.com');
});
test('add primary clears flag on other domains', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'old.example.com', 'is_primary' => true]);

    Artisan::call('dply:site:domain-add', [
        'site' => $site->slug,
        'hostname' => 'new.example.com',
        '--primary' => true,
    ]);

    $primaries = $site->domains()->where('is_primary', true)->get();
    expect($primaries)->toHaveCount(1);
    expect($primaries->first()->hostname)->toBe('new.example.com');
});
test('add rejects duplicate on same site', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'jobs.example.com']);

    $exit = Artisan::call('dply:site:domain-add', [
        'site' => $site->slug,
        'hostname' => 'jobs.example.com',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('already exists', $output);
});
test('add rejects invalid hostname', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:domain-add', [
        'site' => $site->slug,
        'hostname' => 'not a hostname',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('does not look valid', $output);
});
test('remove deletes domain', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'a.example.com']);
    $site->domains()->create(['hostname' => 'b.example.com']);

    $exit = Artisan::call('dply:site:domain-remove', [
        'site' => $site->slug,
        'hostname' => 'a.example.com',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0);
    expect($decoded['removed'])->toBe('a.example.com');
    expect($site->domains()->count())->toBe(1);
});
test('remove refuses only domain without force', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'only.example.com']);

    $exit = Artisan::call('dply:site:domain-remove', [
        'site' => $site->slug,
        'hostname' => 'only.example.com',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('only domain', $output);
    expect($site->domains()->count())->toBe(1);
});
test('remove force overrides only domain check', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'only.example.com']);

    $exit = Artisan::call('dply:site:domain-remove', [
        'site' => $site->slug,
        'hostname' => 'only.example.com',
        '--force' => true,
    ]);

    expect($exit)->toBe(0);
    expect($site->domains()->count())->toBe(0);
});
test('remove refuses primary when others exist', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'primary.example.com', 'is_primary' => true]);
    $site->domains()->create(['hostname' => 'alias.example.com', 'is_primary' => false]);

    $exit = Artisan::call('dply:site:domain-remove', [
        'site' => $site->slug,
        'hostname' => 'primary.example.com',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('primary domain', $output);
});
test('remove fails when hostname not found', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:domain-remove', [
        'site' => $site->slug,
        'hostname' => 'missing.example.com',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Domain not found', $output);
});
function makeSite(): Site
{
    $server = Server::factory()->create();

    return Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs',
    ]);
}
