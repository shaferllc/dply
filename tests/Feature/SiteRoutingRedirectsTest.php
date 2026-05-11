<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteRedirectKind;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class SiteRoutingRedirectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_http_redirect_with_comment(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'redirects')
            ->set('new_redirect_kind', 'http')
            ->set('new_redirect_from', '/old')
            ->set('new_redirect_to', 'https://example.com/new')
            ->set('new_redirect_code', 302)
            ->set('new_redirect_comment', 'Mailchimp legacy URL')
            ->call('addRedirectRule')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('site_redirects', [
            'site_id' => $site->id,
            'from_path' => '/old',
            'to_url' => 'https://example.com/new',
            'status_code' => 302,
            'comment' => 'Mailchimp legacy URL',
        ]);
    }

    public function test_inline_edit_redirect_changes_kind_and_status(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();
        $redirect = SiteRedirect::query()->create([
            'site_id' => $site->id,
            'kind' => SiteRedirectKind::Http,
            'from_path' => '/legacy',
            'to_url' => 'https://example.com',
            'status_code' => 301,
            'sort_order' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'redirects')
            ->call('editRedirect', $redirect->id)
            ->assertSet('editing_redirect_id', (string) $redirect->id)
            ->assertSet('editing_redirect_code', 301)
            ->set('editing_redirect_code', 302)
            ->set('editing_redirect_comment', 'updated')
            ->call('saveEditedRedirect')
            ->assertHasNoErrors();

        $fresh = $redirect->fresh();
        $this->assertSame(302, (int) $fresh->status_code);
        $this->assertSame('updated', $fresh->comment);
    }

    public function test_confirm_remove_redirect(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();
        $redirect = SiteRedirect::query()->create([
            'site_id' => $site->id,
            'kind' => SiteRedirectKind::Http,
            'from_path' => '/x',
            'to_url' => '/y',
            'status_code' => 301,
            'sort_order' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'redirects')
            ->call('confirmRemoveRedirect', $redirect->id)
            ->assertSet('showConfirmActionModal', true)
            ->call('confirmActionModal');

        $this->assertNull($redirect->fresh());
    }

    public function test_bulk_import_redirects_csv(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'redirects')
            ->set('bulk_redirect_input', "/a,/b\n/c,https://x.com,302\n")
            ->call('bulkImportRedirects')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('site_redirects', ['from_path' => '/a', 'to_url' => '/b', 'status_code' => 301]);
        $this->assertDatabaseHas('site_redirects', ['from_path' => '/c', 'to_url' => 'https://x.com', 'status_code' => 302]);
    }

    public function test_bulk_import_aborts_on_invalid_status_code(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'redirects')
            ->set('bulk_redirect_input', "/a,/b,999\n")
            ->call('bulkImportRedirects')
            ->assertHasErrors(['bulk_redirect_input']);

        $this->assertDatabaseMissing('site_redirects', ['from_path' => '/a']);
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

        $server = Server::factory()->ready()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        return [$user, $server, $site];
    }
}
