<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteTenantDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class SiteRoutingTenantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_tenant_with_comment_replaces_old_notes_field(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'tenants')
            ->set('new_tenant_hostname', 'acme.example.com')
            ->set('new_tenant_key', 'acme')
            ->set('new_tenant_label', 'Acme Corp')
            ->set('new_tenant_comment', 'App resolver uses hostname')
            ->call('addTenantDomain')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('site_tenant_domains', [
            'hostname' => 'acme.example.com',
            'tenant_key' => 'acme',
            'comment' => 'App resolver uses hostname',
        ]);
    }

    public function test_inline_edit_tenant(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();
        $tenant = SiteTenantDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'old.example.com',
            'tenant_key' => 'oldkey',
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'tenants')
            ->call('editTenantDomain', $tenant->id)
            ->set('editing_tenant_hostname', 'new.example.com')
            ->set('editing_tenant_comment', 'updated')
            ->call('saveEditedTenantDomain')
            ->assertHasNoErrors();

        $fresh = $tenant->fresh();
        $this->assertSame('new.example.com', $fresh->hostname);
        $this->assertSame('updated', $fresh->comment);
    }

    public function test_confirm_remove_tenant(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();
        $tenant = SiteTenantDomain::query()->create(['site_id' => $site->id, 'hostname' => 'gone.example.com']);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'tenants')
            ->call('confirmRemoveTenantDomain', (string) $tenant->id)
            ->assertSet('showConfirmActionModal', true)
            ->call('confirmActionModal');

        $this->assertNull($tenant->fresh());
    }

    public function test_bulk_import_tenants(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->makeUserSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'routing'])
            ->set('routingTab', 'tenants')
            ->set('bulk_tenant_input', "acme.example.com,acme,Acme Corp\nbeta.example.com,beta\n")
            ->call('bulkImportTenantDomains')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('site_tenant_domains', ['hostname' => 'acme.example.com', 'tenant_key' => 'acme', 'label' => 'Acme Corp']);
        $this->assertDatabaseHas('site_tenant_domains', ['hostname' => 'beta.example.com', 'tenant_key' => 'beta', 'label' => null]);
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
