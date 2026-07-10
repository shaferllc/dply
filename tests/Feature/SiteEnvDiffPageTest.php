<?php

declare(strict_types=1);

namespace Tests\Feature\SiteEnvDiffPageTest;

use App\Livewire\Sites\EnvDiff;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteEnvReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('renders in sync when cache matches server', function () {
    [$user, $server, $site] = makeUserSite(['env_file_content' => 'A=one']);
    bindFakeReader("A=one\n");

    // Server .env is deferred to wire:init (loadServerEnv) so first paint stays fast.
    Livewire::actingAs($user)
        ->test(EnvDiff::class, ['server' => $server, 'site' => $site])
        ->call('loadServerEnv')
        ->assertSee('Cache and server are in sync');
});

test('categorizes keys into three buckets', function () {
    [$user, $server, $site] = makeUserSite([
        'env_file_content' => "CACHE_ONLY=c\nSHARED=cache-value",
    ]);
    bindFakeReader("SERVER_ONLY=s\nSHARED=server-value\n");

    Livewire::actingAs($user)
        ->test(EnvDiff::class, ['server' => $server, 'site' => $site])
        ->call('loadServerEnv')
        ->assertSee('CACHE_ONLY')
        ->assertSee('SERVER_ONLY')
        ->assertSee('SHARED')
        ->assertSee('Differs in value');
});

test('masks values by default', function () {
    [$user, $server, $site] = makeUserSite([
        'env_file_content' => 'API_KEY=super-cache-secret',
    ]);
    bindFakeReader("API_KEY=super-server-secret\n");

    Livewire::actingAs($user)
        ->test(EnvDiff::class, ['server' => $server, 'site' => $site])
        ->call('loadServerEnv')
        ->assertDontSee('super-cache-secret')
        ->assertDontSee('super-server-secret')
        ->assertSee('•');
});

test('unsupported runtime renders short circuit', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    // App-Platform host has no server-side .env to diff against.
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_APP_PLATFORM],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $response = $this->actingAs($user)->get(route('sites.env-diff', [
        'server' => $server,
        'site' => $site,
    ]));

    $response->assertOk()
        ->assertSee('does not have a server-side .env file', false);
});

/**
 * @param  array<string, mixed>  $siteAttrs
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeUserSite(array $siteAttrs = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => 'fake-key',
    ]);
    $site = Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ], $siteAttrs));

    return [$user, $server, $site];
}

function bindFakeReader(string $serverEnv): void
{
    app()->bind(SiteEnvReader::class, fn () => new class($serverEnv) extends SiteEnvReader
    {
        public function __construct(private readonly string $payload)
        {
            // Bypass parent constructor; the wrapper is unused for the fake.
        }

        // Must match SiteEnvReader::read() — an incompatible declaration is a
        // PHP fatal that takes down the whole Pest process, not just this test.
        public function read(Site $site, ?string $pathOverride = null): string
        {
            return $this->payload;
        }
    });
}
