<?php

declare(strict_types=1);

namespace Tests\Feature\SiteUrlCommandTest;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('prints primary url with https by default', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'alias.example.com', 'is_primary' => false]);
    $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

    $exit = Artisan::call('dply:site:url', ['site' => $site->slug]);
    $output = trim(Artisan::output());

    expect($exit)->toBe(0);
    expect($output)->toBe('https://jobs.example.com');
});
test('scheme option changes protocol', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

    Artisan::call('dply:site:url', [
        'site' => $site->slug,
        '--scheme' => 'http',
    ]);
    $output = trim(Artisan::output());

    expect($output)->toBe('http://jobs.example.com');
});
test('all flag prints every domain primary first', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'b.example.com', 'is_primary' => false]);
    $site->domains()->create(['hostname' => 'a.example.com', 'is_primary' => true]);

    Artisan::call('dply:site:url', [
        'site' => $site->slug,
        '--all' => true,
    ]);
    $lines = array_values(array_filter(explode("\n", Artisan::output())));

    expect($lines)->toBe([
        'https://a.example.com',
        'https://b.example.com',
    ]);
});
test('json output includes all urls', function () {
    $site = makeSite();
    $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

    Artisan::call('dply:site:url', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['scheme'])->toBe('https');
    expect($decoded['urls'])->toBe(['https://jobs.example.com']);
});
test('exits non zero when no domains', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:url', ['site' => $site->slug]);

    expect($exit)->toBe(1);
});
test('rejects invalid scheme', function () {
    $site = makeSite();

    $exit = Artisan::call('dply:site:url', [
        'site' => $site->slug,
        '--scheme' => 'ftp',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Invalid --scheme', $output);
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:url', ['site' => 'nope']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', $output);
});
function makeSite(): Site
{
    $server = Server::factory()->create();

    return Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs',
    ]);
}
