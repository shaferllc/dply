<?php

namespace Tests\Feature\PrimaryHostnameRenameTest;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Modules\Cloud\Jobs\AttachCloudDomainJob;
use App\Modules\Cloud\Jobs\DetachCloudDomainJob;
use App\Modules\Certificates\Jobs\ExecuteSiteCertificateJob;
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

uses(RefreshDatabase::class);

function makeUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);
    session(['current_organization_id' => $org->id]);

    return $user->fresh();
}

function makeSite(User $user, array $overrides = []): Site
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

test('planner is trivial for greenfield site', function () {
    $user = makeUser();
    $site = makeSite($user);

    $plan = app(PrimaryHostnameRenamePlanner::class)->plan($site, 'new.example.com');

    expect($plan['optIn'])->toBe([]);
    expect(app(PrimaryHostnameRenamePlanner::class)->isTrivial($plan))->toBeTrue();
    expect($plan['auto'])->toHaveCount(2);
});

test('planner surfaces dns zone reuse when apex changes', function () {
    $user = makeUser();
    $site = makeSite($user, ['dns_zone' => null]);

    $plan = app(PrimaryHostnameRenamePlanner::class)->plan($site, 'new.other.com');

    $autoKeys = array_column($plan['auto'], 'key');
    expect($autoKeys)->toContain('dns_zone');
});

test('planner skips dns zone when operator set custom value', function () {
    $user = makeUser();
    $site = makeSite($user, ['dns_zone' => 'pinned.custom.zone']);

    $plan = app(PrimaryHostnameRenamePlanner::class)->plan($site, 'new.other.com');

    $autoKeys = array_column($plan['auto'], 'key');
    expect($autoKeys)->not->toContain('dns_zone');
});

test('planner surfaces cert reissue when active cert misses new hostname', function () {
    $user = makeUser();
    $site = makeSite($user);
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
    expect($optInKeys)->toContain('reissue_cert');
});

test('planner surfaces container backend cycle when backend set', function () {
    $user = makeUser();
    $site = makeSite($user, ['container_backend' => 'digitalocean_app_platform']);

    $plan = app(PrimaryHostnameRenamePlanner::class)->plan($site, 'new.example.com');

    $optInKeys = array_column($plan['optIn'], 'key');
    expect($optInKeys)->toContain('cycle_backend');
});

test('save edited primary domain commits inline when rename is trivial', function () {
    Queue::fake();
    $user = makeUser();
    $site = makeSite($user);
    $primary = $site->primaryDomain();

    Livewire::actingAs($user)
        ->test(SitesShow::class, ['server' => $site->server, 'site' => $site])
        ->call('editDomain', $primary->id)
        ->set('editing_domain_hostname', 'new.example.com')
        ->call('saveEditedDomain')
        ->assertSet('rename_plan', null);

    expect($site->fresh()->primaryDomain()->hostname)->toBe('new.example.com');
    Queue::assertPushed(ApplySiteWebserverConfigJob::class);
    Queue::assertNotPushed(ExecuteSiteCertificateJob::class);
    Queue::assertNotPushed(AttachCloudDomainJob::class);
    Queue::assertNotPushed(DetachCloudDomainJob::class);
});

test('save edited primary domain opens modal when cert makes rename non trivial', function () {
    Queue::fake();
    $user = makeUser();
    $site = makeSite($user);
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

    expect($component->get('rename_plan'))->not->toBeNull();
    expect($site->fresh()->primaryDomain()->hostname)->toBe('old.example.com');
    Queue::assertNotPushed(ApplySiteWebserverConfigJob::class);
    Queue::assertNotPushed(ExecuteSiteCertificateJob::class);
    Queue::assertNotPushed(AttachCloudDomainJob::class);
    Queue::assertNotPushed(DetachCloudDomainJob::class);
});

test('confirm rename with cert optin queues reissue and writes audit', function () {
    Queue::fake();
    $user = makeUser();
    $site = makeSite($user);
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

    expect($site->fresh()->primaryDomain()->hostname)->toBe('new.example.com');
    Queue::assertPushed(ApplySiteWebserverConfigJob::class);
    Queue::assertPushed(ExecuteSiteCertificateJob::class);

    $newCert = SiteCertificate::query()
        ->where('site_id', $site->id)
        ->where('id', '!=', $oldCert->id)
        ->latest('created_at')
        ->first();
    expect($newCert)->not->toBeNull();
    expect($newCert->domainHostnames())->toContain('new.example.com');

    $audit = SiteAuditEvent::query()
        ->where('site_id', $site->id)
        ->where('action', 'site_primary_hostname_renamed')
        ->first();
    expect($audit)->not->toBeNull();
    expect($audit->payload['old_hostname'])->toBe('old.example.com');
    expect($audit->payload['new_hostname'])->toBe('new.example.com');
    expect($audit->payload['cascades'])->toContain('reissue_cert');
});

test('confirm rename with backend optin queues detach then attach', function () {
    Queue::fake();
    $user = makeUser();
    $site = makeSite($user, ['container_backend' => 'digitalocean_app_platform']);
    $primary = $site->primaryDomain();

    Livewire::actingAs($user)
        ->test(SitesShow::class, ['server' => $site->server, 'site' => $site])
        ->call('editDomain', $primary->id)
        ->set('editing_domain_hostname', 'new.example.com')
        ->call('saveEditedDomain')
        ->set('rename_cycle_backend', true)
        ->call('confirmPrimaryHostnameRename')
        ->assertSet('rename_plan', null);

    Queue::assertPushed(DetachCloudDomainJob::class, fn ($job) => $job->hostname === 'old.example.com');
    Queue::assertPushed(AttachCloudDomainJob::class, fn ($job) => $job->hostname === 'new.example.com');
});

test('cancel rename leaves state unchanged and clears modal', function () {
    Queue::fake();
    $user = makeUser();
    $site = makeSite($user, ['container_backend' => 'digitalocean_app_platform']);
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

    expect($site->fresh()->primaryDomain()->hostname)->toBe('old.example.com');
    Queue::assertNotPushed(ApplySiteWebserverConfigJob::class);
    Queue::assertNotPushed(ExecuteSiteCertificateJob::class);
    Queue::assertNotPushed(AttachCloudDomainJob::class);
    Queue::assertNotPushed(DetachCloudDomainJob::class);
});

test('editing non primary domain skips rename flow', function () {
    Queue::fake();
    $user = makeUser();
    $site = makeSite($user);
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

    expect($component->get('rename_plan'))->toBeNull();
    expect($alias->fresh()->hostname)->toBe('new-alias.example.com');
    expect($site->fresh()->primaryDomain()->hostname)->toBe('old.example.com');
    Queue::assertPushed(ApplySiteWebserverConfigJob::class);
});
