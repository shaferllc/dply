<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Scaffold\PlaceholderDnsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The Site::deleting boot hook must call PlaceholderDnsManager->release()
 * for every site delete. release() is idempotent and safely no-ops when
 * meta.scaffold.placeholder_dns is absent, so non-scaffolded sites pass
 * through cleanly without any DNS provider call.
 */
class SiteDeletionPlaceholderReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeSite(array $meta = []): Site
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

    public function test_site_delete_invokes_placeholder_release_exactly_once(): void
    {
        $site = $this->makeSite([
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
    }

    public function test_non_scaffolded_site_delete_still_invokes_release_idempotently(): void
    {
        // The hook calls release() unconditionally — release() itself
        // short-circuits when meta.scaffold.placeholder_dns is absent.
        // We assert the contract here so adding new "scaffold-only"
        // gating in the hook later doesn't accidentally skip cleanup
        // for sites whose meta drifted but who DO have a stale record.
        $site = $this->makeSite(meta: []); // no scaffold meta at all

        $dns = Mockery::mock(PlaceholderDnsManager::class);
        $dns->shouldReceive('release')->once()->andReturnNull();
        app()->instance(PlaceholderDnsManager::class, $dns);

        $site->delete();
    }

    public function test_release_failure_does_not_block_site_deletion(): void
    {
        // A transient DNS provider failure must not prevent the user
        // from deleting their site row — the orphaned record is
        // recoverable, the inability to delete a site row is not.
        $site = $this->makeSite([
            'scaffold' => [
                'framework' => 'wordpress',
                'placeholder_dns' => ['hostname' => 'doomed.198-51-100-9.nip.io', 'source' => 'dns_provider', 'zone' => 'ondply.io', 'record_id' => '999'],
            ],
        ]);

        $dns = Mockery::mock(PlaceholderDnsManager::class);
        $dns->shouldReceive('release')->andThrow(new \RuntimeException('DigitalOcean API timeout'));
        app()->instance(PlaceholderDnsManager::class, $dns);

        $site->delete();

        $this->assertSame(0, Site::query()->where('id', $site->id)->count(),
            'Site row must be deleted even when placeholder release throws — the hook swallows the error');
    }
}
