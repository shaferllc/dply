<?php

declare(strict_types=1);

namespace Tests\Feature\Models\SiteDeletionPlaceholderReleaseTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Scaffold\Services\PlaceholderDnsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});
function makeSite(array $meta = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'about-to-die',
        'meta' => $meta,
    ]);
}
test('site delete invokes placeholder release exactly once', function () {
    $site = makeSite([
        'scaffold' => [
            'framework' => 'wordpress',
            'placeholder_dns' => ['hostname' => 'about-to-die.198-51-100-2.nip.io', 'source' => 'nip.io'],
        ],
    ]);

    $dns = Mockery::mock(PlaceholderDnsManager::class);
    $dns->shouldReceive('release')
        ->once()
        ->withArgs(fn ($s) => $s->id === $site->id);
    app()->instance(PlaceholderDnsManager::class, $dns);

    $site->delete();

    // Mockery's tearDown verifies release was called once; this assertion satisfies
    // PHPUnit's "risky test" rule (it requires at least one explicit assertion per test).
    expect(Site::query()->where('id', $site->id)->count())->toBe(0);
});
test('non scaffolded site delete still invokes release idempotently', function () {
    // The hook calls release() unconditionally — release() itself
    // short-circuits when meta.scaffold.placeholder_dns is absent.
    // We assert the contract here so adding new "scaffold-only"
    // gating in the hook later doesn't accidentally skip cleanup
    // for sites whose meta drifted but who DO have a stale record.
    $site = makeSite(meta: []);

    // no scaffold meta at all
    $dns = Mockery::mock(PlaceholderDnsManager::class);
    $dns->shouldReceive('release')->once()->andReturnNull();
    app()->instance(PlaceholderDnsManager::class, $dns);

    $site->delete();

    expect(Site::query()->where('id', $site->id)->count())->toBe(0);
});
test('release failure does not block site deletion', function () {
    // A transient DNS provider failure must not prevent the user
    // from deleting their site row — the orphaned record is
    // recoverable, the inability to delete a site row is not.
    $site = makeSite([
        'scaffold' => [
            'framework' => 'wordpress',
            'placeholder_dns' => ['hostname' => 'doomed.198-51-100-9.nip.io', 'source' => 'dns_provider', 'zone' => 'ondply.io', 'record_id' => '999'],
        ],
    ]);

    $dns = Mockery::mock(PlaceholderDnsManager::class);
    $dns->shouldReceive('release')->andThrow(new \RuntimeException('DigitalOcean API timeout'));
    app()->instance(PlaceholderDnsManager::class, $dns);

    $site->delete();

    expect(Site::query()->where('id', $site->id)->count())->toBe(0, 'Site row must be deleted even when placeholder release throws — the hook swallows the error');
});
