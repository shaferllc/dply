<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Domains tab — env-page-style affordances: add with comment, inline edit,
 * confirm-then-delete, bulk import. Auto-apply on every mutation.
 */
class SiteRoutingDomainsTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_domain_with_comment_and_auto_applies(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'domains')
            ->set('new_domain_hostname', 'foo.example.com')
            ->set('new_domain_comment', 'EU CDN')
            ->call('addDomain')
            ->assertHasNoErrors()
            ->assertSet('new_domain_hostname', '')
            ->assertSet('new_domain_comment', '');

        $this->assertDatabaseHas('site_domains', [
            'site_id' => $site->id,
            'hostname' => 'foo.example.com',
            'comment' => 'EU CDN',
        ]);
        Bus::assertDispatched(ApplySiteWebserverConfigJob::class);
    }

    public function test_inline_edit_updates_hostname_and_comment(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();
        $domain = SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'old.example.com',
            'is_primary' => false,
            'www_redirect' => false,
            'comment' => 'old comment',
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'domains')
            ->call('editDomain', $domain->id)
            ->assertSet('editing_domain_id', (string) $domain->id)
            ->assertSet('editing_domain_hostname', 'old.example.com')
            ->assertSet('editing_domain_comment', 'old comment')
            ->set('editing_domain_hostname', 'new.example.com')
            ->set('editing_domain_comment', 'new comment')
            ->call('saveEditedDomain')
            ->assertHasNoErrors()
            ->assertSet('editing_domain_id', null);

        $this->assertSame('new.example.com', $domain->fresh()->hostname);
        $this->assertSame('new comment', $domain->fresh()->comment);
    }

    public function test_confirm_remove_domain_routes_through_modal(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();
        $domain = SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'gone.example.com',
            'is_primary' => false,
            'www_redirect' => false,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'domains')
            ->call('confirmRemoveDomain', $domain->id)
            ->assertSet('showConfirmActionModal', true)
            ->assertSet('confirmActionModalMethod', 'removeDomain');

        // Domain still present until the modal's Confirm fires.
        $this->assertNotNull($domain->fresh());

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'domains')
            ->call('confirmRemoveDomain', $domain->id)
            ->call('confirmActionModal');

        $this->assertNull($domain->fresh());
    }

    public function test_bulk_import_creates_new_domains_and_skips_existing(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'existing.example.com',
            'is_primary' => false,
            'www_redirect' => false,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'domains')
            ->set('bulk_domain_input', "one.example.com\nexisting.example.com\ntwo.example.com\n")
            ->call('bulkImportDomains')
            ->assertHasNoErrors()
            ->assertSet('bulk_domain_input', '');

        $this->assertDatabaseHas('site_domains', ['hostname' => 'one.example.com']);
        $this->assertDatabaseHas('site_domains', ['hostname' => 'two.example.com']);
        // existing.example.com only present once (skip on collision).
        $this->assertSame(1, SiteDomain::query()->where('hostname', 'existing.example.com')->count());
    }

    public function test_bulk_import_aborts_on_invalid_hostname(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'domains')
            ->set('bulk_domain_input', "good.example.com\nnot a hostname\n")
            ->call('bulkImportDomains')
            ->assertHasErrors(['bulk_domain_input']);

        // Nothing imported — abort-on-error is the convention from env bulk import.
        $this->assertDatabaseMissing('site_domains', ['hostname' => 'good.example.com']);
    }

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function makeUserSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        return [$user, $server, $site];
    }
}
