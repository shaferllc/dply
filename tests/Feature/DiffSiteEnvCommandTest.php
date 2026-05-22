<?php

declare(strict_types=1);

namespace Tests\Feature\DiffSiteEnvCommandTest;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\SiteEnvReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('categorizes keys with a mocked server read', function () {
    $site = makeSiteWithEnvSupport(env: "CACHE_ONLY=c\nSHARED=cache-value\nIDENTICAL=same");
    bindFakeReader("SERVER_ONLY=s\nSHARED=server-value\nIDENTICAL=same\n");

    Artisan::call('dply:site:env-diff', [
        'site' => $site->slug,
        '--reveal' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['in_sync'])->toBeFalse();
    expect($decoded['only_in_cache'])->toBe(['CACHE_ONLY']);
    expect($decoded['only_in_server'])->toBe(['SERVER_ONLY']);
    expect(array_keys($decoded['differs']))->toBe(['SHARED']);
    expect($decoded['differs']['SHARED']['cache'])->toBe('cache-value');
    expect($decoded['differs']['SHARED']['server'])->toBe('server-value');
});
test('reports in sync when cache matches server', function () {
    $site = makeSiteWithEnvSupport(env: 'A=one');
    bindFakeReader("A=one\n");

    Artisan::call('dply:site:env-diff', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['in_sync'])->toBeTrue();
});
test('masks values in differs by default', function () {
    $site = makeSiteWithEnvSupport(env: 'API_KEY=super-cache-secret');
    bindFakeReader("API_KEY=super-server-secret\n");

    Artisan::call('dply:site:env-diff', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    $this->assertStringNotContainsString('super-cache-secret', json_encode($decoded));
    $this->assertStringNotContainsString('super-server-secret', json_encode($decoded));
    $this->assertStringContainsString('•', $decoded['differs']['API_KEY']['cache']);
});
test('unsupported runtime short circuits', function () {
    $site = makeSiteWithoutEnvSupport();

    Artisan::call('dply:site:env-diff', [
        'site' => $site->slug,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['unsupported'])->toBeTrue();
});
test('command fails when site not found', function () {
    $exit = Artisan::call('dply:site:env-diff', ['site' => 'nope']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', $output);
});
function makeSiteWithEnvSupport(string $env = ''): Site
{
    // Default ServerFactory makes a VM-kind server, which is the only host
    // family that supportsEnvPushToHost(). isReady() and an SSH key are
    // required to even attempt a read; tests bind a fake reader so the
    // bytes never matter, but the early-return guards still need to pass.
    $server = Server::factory()->create([
        'ssh_private_key' => 'fake-key',
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs',
        'env_file_content' => $env,
    ]);
}
function makeSiteWithoutEnvSupport(): Site
{
    $server = Server::factory()->create([
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'jobs',
    ]);
}
function bindFakeReader(string $serverEnv): void
{
    $this->app->bind(SiteEnvReader::class, fn () => new class($serverEnv) extends SiteEnvReader
    {
        public function __construct(private readonly string $payload)
        {
            // Bypass parent constructor — we don't need the wrapper for the fake.
        }

        public function read(Site $site): string
        {
            return $this->payload;
        }
    });
}
