<?php

namespace Tests\Feature;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Jobs\AttachEdgeDomainJob;
use App\Jobs\DetachEdgeDomainJob;
use App\Jobs\ExecuteSiteCertificateJob;
use App\Livewire\Sites\Show as SitesShow;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\SiteCertificate;
use App\Models\SiteDomain;
use App\Models\User;
use App\Services\Sites\PrimaryHostnameRenamePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the cascade planner + the rename confirmation flow that fires from
 * Routing > Domains when an operator edits the primary domain row. After the
 * General → Settings IA refactor, the trigger lives in `Sites\Show` rather
 * than `Sites\Settings`.
 */
class PrimaryHostnameRenameTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_organization_id' => $org->id]);
        session(['current_organization_id' => $org->id]);

        return $user->fresh();
    }

    private function makeSite(User $user, array $overrides = []): Site
    {
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ], $overrides));
        SiteDomain::query()->where('site_id', $site->id)->delete();
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'old.example.com',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        return $site->fresh(['domains', 'certificates']);
    }

    public function test_planner_is_trivial_for_greenfield_site(): void
    {
        $user = $this->makeUser();
        $site = $this->makeSite($user);

        $plan = app(PrimaryHostnameRenamePlanner::class)->plan($site, 'new.example.com');

        $this->assertSame([], $plan['optIn']);
        $this->assertTrue(app(PrimaryHostnameRenamePlanner::class)->isTrivial($plan));
        $this->assertCount(2, $plan['auto']);
    }

    public function test_planner_surfaces_dns_zone_reuse_when_apex_changes(): void
    {
        $user = $this->makeUser();
        $site = $this->makeSite($user, ['dns_zone' => null]);

        $plan = app(PrimaryHostnameRenamePlanner::class)->plan($site, 'new.other.com');

        $autoKeys = array_column($plan['auto'], 'key');
        $this->assertContains('dns_zone', $autoKeys);
    }

    public function test_planner_skips_dns_zone_when_operator_set_custom_value(): void
    {
        $user = $this->makeUser();
        $site = $this->makeSite($user, ['dns_zone' => 'pinned.custom.zone']);

        $plan = app(PrimaryHostnameRenamePlanner::class)->plan($site, 'new.other.com');

        $autoKeys = array_column($plan['auto'], 'key');
        $this->assertNotContains('dns_zone', $autoKeys);
    }

    public function test_planner_surfaces_cert_reissue_when_active_cert_misses_new_hostname(): void
    {
        $user = $this->makeUser();
        $site = $this->makeSite($user);
        SiteCertificate::query()->create([
            'site_id' => $site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
            'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
            'domains_json' => ['old.example.com'],
            'status' => SiteCertificate::STATUS_ACTIVE,
        ]);
        $site = $site->fresh(['certificates']);

        $plan = app(PrimaryHostnameRenamePlanner::class)->plan($site, 'new.example.com');

        $optInKeys = array_column($plan['optIn'], 'key');
        $this->assertContains('reissue_cert', $optInKeys);
    }

    public function test_planner_surfaces_container_backend_cycle_when_backend_set(): void
    {
        $user = $this->makeUser();
        $site = $this->makeSite($user, ['container_backend' => 'digitalocean_app_platform']);

        $plan = app(PrimaryHostnameRenamePlanner::class)->plan($site, 'new.example.com');

        $optInKeys = array_column($plan['optIn'], 'key');
        $this->assertContains('cycle_backend', $optInKeys);
    }

    public function test_save_edited_primary_domain_commits_inline_when_rename_is_trivial(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $site = $this->makeSite($user);
        $primary = $site->primaryDomain();

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $site->server, 'site' => $site])
            ->call('editDomain', $primary->id)
            ->set('editing_domain_hostname', 'new.example.com')
            ->call('saveEditedDomain')
            ->assertSet('rename_plan', null);

        $this->assertSame('new.example.com', $site->fresh()->primaryDomain()->hostname);
        Queue::assertPushed(ApplySiteWebserverConfigJob::class);
        Queue::assertNotPushed(ExecuteSiteCertificateJob::class);
        Queue::assertNotPushed(AttachEdgeDomainJob::class);
        Queue::assertNotPushed(DetachEdgeDomainJob::class);
    }

    public function test_save_edited_primary_domain_opens_modal_when_cert_makes_rename_non_trivial(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $site = $this->makeSite($user);
        SiteCertificate::query()->create([
            'site_id' => $site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
            'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
            'domains_json' => ['old.example.com'],
            'status' => SiteCertificate::STATUS_ACTIVE,
        ]);
        $primary = $site->primaryDomain();

        $component = Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $site->server, 'site' => $site])
            ->call('editDomain', $primary->id)
            ->set('editing_domain_hostname', 'new.example.com')
            ->call('saveEditedDomain');

        $this->assertNotNull($component->get('rename_plan'));
        $this->assertSame('old.example.com', $site->fresh()->primaryDomain()->hostname);
        Queue::assertNotPushed(ApplySiteWebserverConfigJob::class);
        Queue::assertNotPushed(ExecuteSiteCertificateJob::class);
        Queue::assertNotPushed(AttachEdgeDomainJob::class);
        Queue::assertNotPushed(DetachEdgeDomainJob::class);
    }

    public function test_confirm_rename_with_cert_optin_queues_reissue_and_writes_audit(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $site = $this->makeSite($user);
        $oldCert = SiteCertificate::query()->create([
            'site_id' => $site->id,
            'scope_type' => SiteCertificate::SCOPE_CUSTOMER,
            'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
            'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
            'domains_json' => ['old.example.com'],
            'status' => SiteCertificate::STATUS_ACTIVE,
        ]);
        $primary = $site->primaryDomain();

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $site->server, 'site' => $site])
            ->call('editDomain', $primary->id)
            ->set('editing_domain_hostname', 'new.example.com')
            ->call('saveEditedDomain')
            ->set('rename_reissue_cert', true)
            ->call('confirmPrimaryHostnameRename')
            ->assertSet('rename_plan', null);

        $this->assertSame('new.example.com', $site->fresh()->primaryDomain()->hostname);
        Queue::assertPushed(ApplySiteWebserverConfigJob::class);
        Queue::assertPushed(ExecuteSiteCertificateJob::class);

        $newCert = SiteCertificate::query()
            ->where('site_id', $site->id)
            ->where('id', '!=', $oldCert->id)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($newCert);
        $this->assertContains('new.example.com', $newCert->domainHostnames());

        $audit = SiteAuditEvent::query()
            ->where('site_id', $site->id)
            ->where('action', 'site_primary_hostname_renamed')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('old.example.com', $audit->payload['old_hostname']);
        $this->assertSame('new.example.com', $audit->payload['new_hostname']);
        $this->assertContains('reissue_cert', $audit->payload['cascades']);
    }

    public function test_confirm_rename_with_backend_optin_queues_detach_then_attach(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $site = $this->makeSite($user, ['container_backend' => 'digitalocean_app_platform']);
        $primary = $site->primaryDomain();

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $site->server, 'site' => $site])
            ->call('editDomain', $primary->id)
            ->set('editing_domain_hostname', 'new.example.com')
            ->call('saveEditedDomain')
            ->set('rename_cycle_backend', true)
            ->call('confirmPrimaryHostnameRename')
            ->assertSet('rename_plan', null);

        Queue::assertPushed(DetachEdgeDomainJob::class, fn ($job) => $job->hostname === 'old.example.com');
        Queue::assertPushed(AttachEdgeDomainJob::class, fn ($job) => $job->hostname === 'new.example.com');
    }

    public function test_cancel_rename_leaves_state_unchanged_and_clears_modal(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $site = $this->makeSite($user, ['container_backend' => 'digitalocean_app_platform']);
        $primary = $site->primaryDomain();

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $site->server, 'site' => $site])
            ->call('editDomain', $primary->id)
            ->set('editing_domain_hostname', 'new.example.com')
            ->call('saveEditedDomain')
            ->call('cancelPrimaryHostnameRename')
            ->assertSet('rename_plan', null)
            ->assertSet('rename_reissue_cert', false)
            ->assertSet('rename_cycle_backend', false);

        $this->assertSame('old.example.com', $site->fresh()->primaryDomain()->hostname);
        Queue::assertNotPushed(ApplySiteWebserverConfigJob::class);
        Queue::assertNotPushed(ExecuteSiteCertificateJob::class);
        Queue::assertNotPushed(AttachEdgeDomainJob::class);
        Queue::assertNotPushed(DetachEdgeDomainJob::class);
    }

    public function test_editing_non_primary_domain_skips_rename_flow(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $site = $this->makeSite($user);
        $alias = SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'alias.example.com',
            'is_primary' => false,
            'www_redirect' => false,
        ]);

        $component = Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $site->server, 'site' => $site])
            ->call('editDomain', $alias->id)
            ->set('editing_domain_hostname', 'new-alias.example.com')
            ->call('saveEditedDomain');

        $this->assertNull($component->get('rename_plan'));
        $this->assertSame('new-alias.example.com', $alias->fresh()->hostname);
        $this->assertSame('old.example.com', $site->fresh()->primaryDomain()->hostname);
        Queue::assertPushed(ApplySiteWebserverConfigJob::class);
    }
}
