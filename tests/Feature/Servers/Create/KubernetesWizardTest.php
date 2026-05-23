<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\Create\KubernetesWizardTest;

use App\Jobs\PollDoksClusterStatusJob;
use App\Livewire\Servers\Create\StepReview;
use App\Livewire\Servers\Create\StepWhat;
use App\Livewire\Servers\Create\StepWhere;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

usesFeatures('workspace.cluster', 'provider.aws', 'provider.aws_eks');

test('choosing kubernetes host kind clears type for provider pick', function () {
    // Choosing the K8s host-kind tile sets provider_host_kind=kubernetes
    // but does NOT pin form.type — the user must then click a provider
    // tile (DO or AWS) which suffixes form.type to {provider}_kubernetes.
    $user = userWithOrganization();
    seedDraftAtStep($user, step: 2);

    Livewire::actingAs($user)
        ->test(StepWhere::class)
        ->call('chooseProviderHostKind', 'kubernetes')
        ->assertSet('form.provider_host_kind', 'kubernetes')
        ->assertSet('form.type', '');
});
test('choosing digitalocean provider for kubernetes pins form type to digitalocean kubernetes', function () {
    $user = userWithOrganization();
    seedDraftAtStep($user, step: 2);

    Livewire::actingAs($user)
        ->test(StepWhere::class)
        ->call('chooseProviderHostKind', 'kubernetes')
        ->call('chooseProvider', 'digitalocean')
        ->assertSet('form.provider_host_kind', 'kubernetes')
        ->assertSet('form.type', 'digitalocean_kubernetes');
});
test('step where validates without region or size when kubernetes', function () {
    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 2);

    // Region + size deliberately blank — should still pass validation for K8s.
    Livewire::actingAs($user)
        ->test(StepWhere::class)
        ->set('form.mode', 'provider')
        ->set('form.provider_host_kind', 'kubernetes')
        ->set('form.type', 'digitalocean_kubernetes')
        ->set('form.provider_credential_id', (string) $credential->id)
        ->set('form.region', '')
        ->set('form.size', '')
        ->call('next')
        ->assertHasNoErrors();
});
test('step what lists clusters from digitalocean api', function () {
    Cache::flush();
    Http::fake([
        'api.digitalocean.com/v2/kubernetes/clusters*' => Http::response([
            'kubernetes_clusters' => [
                ['id' => 'abc-1', 'name' => 'prod-cluster', 'region' => 'nyc3'],
                ['id' => 'def-2', 'name' => 'staging-cluster', 'region' => 'sfo3'],
            ],
        ], 200),
    ]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 3, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
    ]);

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->assertSee('Pick a Kubernetes cluster')
        ->assertSee('prod-cluster')
        ->assertSee('staging-cluster');
});
test('step what auto selects cluster when account has exactly one', function () {
    Cache::flush();
    Http::fake([
        'api.digitalocean.com/v2/kubernetes/clusters*' => Http::response([
            'kubernetes_clusters' => [
                ['id' => 'only-1', 'name' => 'only-cluster', 'region' => 'nyc3'],
            ],
        ], 200),
    ]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 3, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
    ]);

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->assertSet('form.do_kubernetes_cluster_name', 'only-cluster');
});
test('step what does not auto select when account has multiple clusters', function () {
    Cache::flush();
    Http::fake([
        'api.digitalocean.com/v2/kubernetes/clusters*' => Http::response([
            'kubernetes_clusters' => [
                ['id' => 'a', 'name' => 'prod', 'region' => 'nyc3'],
                ['id' => 'b', 'name' => 'staging', 'region' => 'sfo3'],
            ],
        ], 200),
    ]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 3, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
    ]);

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->assertSet('form.do_kubernetes_cluster_name', '');
});
test('step what shows empty state when account has no clusters', function () {
    Cache::flush();
    Http::fake([
        'api.digitalocean.com/v2/kubernetes/clusters*' => Http::response([
            'kubernetes_clusters' => [],
        ], 200),
    ]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 3, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
    ]);

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->assertSee('No managed clusters found in this account.');
});
test('step review shows billing disclosure instead of cost preview', function () {
    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 4, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
        'do_kubernetes_cluster_name' => 'prod-cluster',
        'do_kubernetes_namespace' => 'default',
    ]);

    $response = $this->actingAs($user)->get(route('servers.create.review'));

    $response->assertOk()
        ->assertSee('DigitalOcean bills you directly')
        ->assertSee('prod-cluster')
        ->assertSee('default');
});
test('step where does not block on missing cluster name for kubernetes', function () {
    Cache::flush();
    Http::fake(['api.digitalocean.com/v2/*' => Http::response([
        'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
        'options' => ['versions' => []],
    ], 200)]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 2, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
    ]);

    $rendered = Livewire::actingAs($user)->test(StepWhere::class);
    $preflight = $rendered->viewData('preflight');

    $blockingChecks = collect($preflight['checks'])->where('blocking', true)->values();
    $blockingCount = $blockingChecks->count();

    // Sanity: the cluster-name check is present, just not blocking on step 2.
    $clusterCheck = collect($preflight['checks'])->firstWhere('field', 'do_kubernetes_cluster_name');
    expect($clusterCheck)->not->toBeNull('expected a cluster-name check to be surfaced');
    expect($clusterCheck['blocking'])->toBeFalse('cluster-name check should NOT block on StepWhere');
    expect($clusterCheck['severity'])->toBe('warning');

    // No blocking issues from cluster fields specifically.
    expect($blockingChecks->where('field', 'do_kubernetes_cluster_name')->count())->toBe(0);
});
test('step review still blocks on missing cluster name for kubernetes', function () {
    Cache::flush();
    Http::fake(['api.digitalocean.com/v2/*' => Http::response([
        'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
        'options' => ['versions' => []],
    ], 200)]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 4, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
        'do_kubernetes_namespace' => 'default',
        // Deliberately leaving do_kubernetes_cluster_name empty.
    ]);

    $rendered = Livewire::actingAs($user)->test(StepReview::class);
    $preflight = $rendered->viewData('preflight');

    $clusterCheck = collect($preflight['checks'])->firstWhere('field', 'do_kubernetes_cluster_name');
    expect($clusterCheck)->not->toBeNull();
    expect($clusterCheck['blocking'])->toBeTrue('cluster-name check should block on StepReview');
    expect($clusterCheck['severity'])->toBe('error');
});
test('step what seeds default cluster name when create new active', function () {
    Cache::flush();
    Http::fake(['api.digitalocean.com/v2/*' => Http::response([
        'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
        'options' => ['versions' => []],
    ], 200)]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 3, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
        'do_kubernetes_source' => 'new',
        // No do_kubernetes_new_name → mount should fill one in.
    ]);

    $name = Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->get('form.do_kubernetes_new_name');

    expect($name)->toMatch('/^dply-cluster-[0-9a-f]{6}$/');
});
test('step what regenerate button rolls a new cluster name', function () {
    Cache::flush();
    Http::fake(['api.digitalocean.com/v2/*' => Http::response([
        'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
        'options' => ['versions' => []],
    ], 200)]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 3, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
        'do_kubernetes_source' => 'new',
        'do_kubernetes_new_name' => 'dply-cluster-aaaaaa',
    ]);

    $component = Livewire::actingAs($user)->test(StepWhat::class);
    $before = $component->get('form.do_kubernetes_new_name');
    $component->call('regenerateNewClusterName');
    $after = $component->get('form.do_kubernetes_new_name');

    $this->assertNotSame($before, $after, 'regenerate should produce a different name');
    expect($after)->toMatch('/^dply-cluster-[0-9a-f]{6}$/');
});
test('step what does not clobber user edited cluster name', function () {
    Cache::flush();
    Http::fake(['api.digitalocean.com/v2/*' => Http::response([
        'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
        'options' => ['versions' => []],
    ], 200)]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 3, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
        'do_kubernetes_source' => 'new',
        'do_kubernetes_new_name' => 'my-handpicked-name',
    ]);

    $name = Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->get('form.do_kubernetes_new_name');

    expect($name)->toBe('my-handpicked-name', 'mount auto-default must not overwrite an existing name');
});
test('step what continue button disabled when no cluster picked in existing mode', function () {
    Cache::flush();
    Http::fake(['api.digitalocean.com/v2/*' => Http::response([
        'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
        'options' => ['versions' => []],
    ], 200)]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 3, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
        // existing mode (default) with no cluster picked → can't continue
    ]);

    expect(Livewire::actingAs($user)->test(StepWhat::class)->viewData('canContinue'))->toBeFalse('Continue button should be disabled when existing-mode and no cluster picked');
});
test('step what continue button enabled once create new fields are filled', function () {
    Cache::flush();
    Http::fake(['api.digitalocean.com/v2/*' => Http::response([
        'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
        'options' => ['versions' => []],
    ], 200)]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 3, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
        'do_kubernetes_source' => 'new',
        'do_kubernetes_new_name' => 'fresh-cluster',
        'do_kubernetes_new_region' => 'nyc3',
        'do_kubernetes_new_node_size' => 's-2vcpu-4gb',
        'do_kubernetes_new_node_count' => 2,
        'do_kubernetes_namespace' => 'default',
    ]);

    expect(Livewire::actingAs($user)->test(StepWhat::class)->viewData('canContinue'))->toBeTrue('Continue button should be enabled when all create-new fields are filled');
});
test('step what renders create new toggle for doks', function () {
    Cache::flush();
    Http::fake([
        'api.digitalocean.com/v2/kubernetes/clusters*' => Http::response(['kubernetes_clusters' => []], 200),
        'api.digitalocean.com/v2/regions*' => Http::response(['regions' => [['slug' => 'nyc3', 'name' => 'New York 3', 'available' => true]]], 200),
        'api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => [['slug' => 's-2vcpu-4gb', 'memory' => 4096, 'vcpus' => 2, 'disk' => 80, 'price_monthly' => 24.0, 'available' => true]]], 200),
        'api.digitalocean.com/v2/kubernetes/options*' => Http::response(['options' => ['versions' => [['slug' => '1.30.1-do.0', 'kubernetes_version' => '1.30.1']]]], 200),
    ]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 3, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
    ]);

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->assertSee('Use existing cluster')
        ->assertSee('Create new');
});
test('step what switches to create new form and validates fields', function () {
    Cache::flush();
    Http::fake([
        'api.digitalocean.com/v2/*' => Http::response([
            'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
            'options' => ['versions' => []],
        ], 200),
    ]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 3, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
        'do_kubernetes_source' => 'new',
    ]);

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->set('form.do_kubernetes_new_name', '')
        ->set('form.do_kubernetes_new_region', '')
        ->set('form.do_kubernetes_new_node_size', '')
        ->call('next')
        ->assertHasErrors([
            'form.do_kubernetes_new_name',
            'form.do_kubernetes_new_region',
            'form.do_kubernetes_new_node_size',
        ]);
});
test('servers show does not loop for kubernetes provisioning servers', function () {
    // Reproduces the "too many redirects" loop: previously servers.show
    // sent K8s PROVISIONING servers to the journey page, but the journey
    // component's bootWorkspace rejects non-VM hosts and bounces back to
    // servers.show. The route now short-circuits to overview for non-VM
    // hosts so the loop can't form.
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PROVISIONING,
        'meta' => [
            'host_kind' => Server::HOST_KIND_KUBERNETES,
            'kubernetes' => [
                'provider' => 'digitalocean',
                'cluster_name' => 'fresh',
                'provisioned_by_dply' => true,
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('servers.show', $server))
        ->assertRedirect(route('servers.cluster', $server));
});
test('storing create new doks calls digitalocean api and lands provisioning', function () {
    // The store action dispatches PollDoksClusterStatusJob — fake the queue
    // so the poller doesn't run synchronously and overwrite the
    // PROVISIONING state we're asserting on. The poller has its own tests.
    Queue::fake();
    Cache::flush();
    Http::fake(function ($request) {
        // POST /kubernetes/clusters → create response. Anything else (e.g.
        // the catalog GET that fires during preflight) returns an empty list.
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/kubernetes/clusters')) {
            return Http::response(['kubernetes_cluster' => [
                'id' => 'new-cluster-id',
                'name' => 'fresh-cluster',
                'region' => 'nyc3',
                'status' => ['state' => 'provisioning'],
                'node_pools' => [['size' => 's-2vcpu-4gb', 'count' => 2]],
            ]], 201);
        }

        return Http::response([
            'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
            // The service falls back to the first available version when
            // the form didn't pin one — fake needs at least one entry.
            'options' => ['versions' => [['slug' => '1.30.1-do.0', 'kubernetes_version' => '1.30.1']]],
        ], 200);
    });

    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 4, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-new',
        'do_kubernetes_source' => 'new',
        'do_kubernetes_new_name' => 'fresh-cluster',
        'do_kubernetes_new_region' => 'nyc3',
        'do_kubernetes_new_node_size' => 's-2vcpu-4gb',
        'do_kubernetes_new_node_count' => 2,
        'do_kubernetes_new_ha' => false,
        'do_kubernetes_namespace' => 'apps',
    ]);

    Livewire::actingAs($user)
        ->test(StepReview::class)
        ->call('store');

    $server = Server::query()->where('name', 'doks-new')->first();
    expect($server)->not->toBeNull();
    expect($server->status)->toBe(Server::STATUS_PROVISIONING);
    expect($server->meta['kubernetes']['cluster_name'])->toBe('fresh-cluster');
    expect($server->meta['kubernetes']['cluster_id'])->toBe('new-cluster-id');
    expect($server->meta['kubernetes']['region'])->toBe('nyc3');
    expect($server->meta['kubernetes']['provisioned_by_dply'])->toBeTrue();

    // Verify the DO create endpoint was actually called.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/kubernetes/clusters')
        && $request->method() === 'POST'
        && $request['name'] === 'fresh-cluster'
        && $request['node_pools'][0]['size'] === 's-2vcpu-4gb');

    // And the poller was queued so the server eventually flips to READY.
    Queue::assertPushed(PollDoksClusterStatusJob::class);
});
test('doks node size picker only shows kubernetes eligible sizes', function () {
    Cache::flush();
    Http::fake([
        'api.digitalocean.com/v2/kubernetes/clusters*' => Http::response(['kubernetes_clusters' => []], 200),
        'api.digitalocean.com/v2/kubernetes/options*' => Http::response(['options' => [
            'regions' => [['slug' => 'nyc3']],
            'versions' => [['slug' => '1.30.1-do.0', 'kubernetes_version' => '1.30.1']],
            // Only s-2vcpu-4gb is allowed for DOKS in this fake.
            'sizes' => [['slug' => 's-2vcpu-4gb']],
        ]], 200),
        'api.digitalocean.com/v2/regions*' => Http::response(['regions' => [
            ['slug' => 'nyc3', 'name' => 'New York 3', 'available' => true],
            ['slug' => 'syd1', 'name' => 'Sydney 1', 'available' => true],
        ]], 200),
        'api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => [
            ['slug' => 's-1vcpu-512mb-10gb', 'memory' => 512, 'vcpus' => 1, 'disk' => 10, 'price_monthly' => 4.0, 'available' => true],
            ['slug' => 's-2vcpu-4gb', 'memory' => 4096, 'vcpus' => 2, 'disk' => 80, 'price_monthly' => 24.0, 'available' => true],
            ['slug' => 'so-32vcpu-256gb', 'memory' => 262144, 'vcpus' => 32, 'disk' => 1000, 'price_monthly' => 1920.0, 'available' => true],
        ]], 200),
    ]);

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 3, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-test',
        'do_kubernetes_source' => 'new',
    ]);

    $sizes = Livewire::actingAs($user)->test(StepWhat::class)->viewData('kubernetesNodeSizes');
    $slugs = array_column($sizes, 'value');

    expect($slugs)->toBe(['s-2vcpu-4gb'], 'only sizes published by /kubernetes/options.sizes should be offered to the create-new picker');

    // Regions should also be filtered to the DOKS-eligible set.
    $regions = Livewire::actingAs($user)->test(StepWhat::class)->viewData('kubernetesRegions');
    expect(array_column($regions, 'value'))->toBe(['nyc3']);
});
test('create new with empty version resolves latest from options', function () {
    Cache::flush();
    $sentBodies = [];
    Http::fake(function ($request) use (&$sentBodies) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/kubernetes/clusters')) {
            $sentBodies[] = $request->data();

            return Http::response(['kubernetes_cluster' => [
                'id' => 'id', 'name' => 'fresh', 'region' => 'nyc3',
                'status' => ['state' => 'provisioning'],
            ]], 201);
        }
        if (str_contains($request->url(), '/kubernetes/options')) {
            return Http::response(['options' => ['versions' => [
                ['slug' => '1.31.0-do.0', 'kubernetes_version' => '1.31.0'],
                ['slug' => '1.30.1-do.0', 'kubernetes_version' => '1.30.1'],
            ]]], 200);
        }

        return Http::response(['kubernetes_clusters' => [], 'regions' => [], 'sizes' => []], 200);
    });

    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 4, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-version-fallback',
        'do_kubernetes_source' => 'new',
        'do_kubernetes_new_name' => 'fresh',
        'do_kubernetes_new_region' => 'nyc3',
        'do_kubernetes_new_node_size' => 's-2vcpu-4gb',
        'do_kubernetes_new_node_count' => 2,
        'do_kubernetes_new_ha' => false,
        'do_kubernetes_new_version' => '', // ← deliberately empty
        'do_kubernetes_namespace' => 'default',
    ]);

    Livewire::actingAs($user)->test(StepReview::class)->call('store');

    expect($sentBodies)->not->toBeEmpty('no create-cluster POST captured');
    expect($sentBodies[0]['version'])->toBe('1.31.0-do.0', 'service should fall back to the newest published DOKS version slug when the form leaves it blank');
});
test('storing create new doks surfaces api failure as validation error', function () {
    Cache::flush();
    Http::fake(function ($request) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/kubernetes/clusters')) {
            return Http::response([
                'id' => 'unprocessable_entity',
                'message' => 'cluster name already exists',
            ], 422);
        }

        return Http::response([
            'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
            'options' => ['versions' => []],
        ], 200);
    });

    $user = userWithOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 4, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-dup',
        'do_kubernetes_source' => 'new',
        'do_kubernetes_new_name' => 'taken-name',
        'do_kubernetes_new_region' => 'nyc3',
        'do_kubernetes_new_node_size' => 's-2vcpu-4gb',
        'do_kubernetes_new_node_count' => 2,
        'do_kubernetes_namespace' => 'apps',
    ]);

    Livewire::actingAs($user)
        ->test(StepReview::class)
        ->call('store')
        ->assertHasErrors(['form.do_kubernetes_new_name']);

    expect(Server::query()->where('name', 'doks-dup')->first())->toBeNull();
});
test('choosing aws provider for kubernetes pins form type to aws kubernetes', function () {
    $user = userWithOrganization();
    seedDraftAtStep($user, step: 2);

    Livewire::actingAs($user)
        ->test(StepWhere::class)
        ->call('chooseProviderHostKind', 'kubernetes')
        ->call('chooseProvider', 'aws')
        ->assertSet('form.provider_host_kind', 'kubernetes')
        ->assertSet('form.type', 'aws_kubernetes');
});
test('storing aws kubernetes server lands status ready immediately', function () {
    // Store action now dispatches PollEksClusterStatusJob + calls
    // AwsEksService::getCluster for the cluster_id lookup. Fake the queue
    // so the poller doesn't run synchronously and overwrite state; the
    // getCluster call goes through the AWS SDK which we don't fake here
    // so we expect it to fail silently and the store to proceed without
    // a cluster_id (matching the production "AWS hiccup at register time"
    // graceful path).
    Queue::fake();

    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws',
        'credentials' => [
            'access_key_id' => 'k',
            'secret_access_key' => 's',
            'region' => 'us-west-2',
        ],
    ]);
    seedDraftAtStep($user, step: 4, payload: [
        'mode' => 'provider',
        'type' => 'aws_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'eks-prod',
        'do_kubernetes_cluster_name' => 'prod-eks',
        'do_kubernetes_namespace' => 'apps',
        'do_kubernetes_aws_region' => 'us-west-2',
    ]);

    Livewire::actingAs($user)
        ->test(StepReview::class)
        ->call('store');

    $server = Server::query()->where('name', 'eks-prod')->first();
    expect($server)->not->toBeNull('EKS server was not created');
    expect($server->status)->toBe(Server::STATUS_READY);
    expect($server->health_status)->toBe(Server::HEALTH_REACHABLE);
    expect($server->meta['host_kind'])->toBe(Server::HOST_KIND_KUBERNETES);
    expect($server->meta['kubernetes']['provider'])->toBe('aws');
    expect($server->meta['kubernetes']['cluster_name'])->toBe('prod-eks');
    expect($server->meta['kubernetes']['namespace'])->toBe('apps');
    expect($server->meta['kubernetes']['region'])->toBe('us-west-2');
});
test('storing kubernetes server lands status ready immediately', function () {
    // Existing-mode store now does a getKubernetesClusters() lookup to find
    // the cluster_id, and dispatches a poller. Fake the HTTP so the lookup
    // works and fake the queue so the poller doesn't run synchronously.
    Queue::fake();
    Cache::flush();
    Http::fake([
        'api.digitalocean.com/v2/kubernetes/clusters*' => Http::response(['kubernetes_clusters' => [
            ['id' => 'cluster-id', 'name' => 'prod-cluster', 'region' => 'nyc3'],
        ]], 200),
    ]);

    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);
    seedDraftAtStep($user, step: 4, payload: [
        'mode' => 'provider',
        'type' => 'digitalocean_kubernetes',
        'provider_host_kind' => 'kubernetes',
        'provider_credential_id' => (string) $credential->id,
        'name' => 'doks-prod',
        'do_kubernetes_cluster_name' => 'prod-cluster',
        'do_kubernetes_namespace' => 'apps',
    ]);

    Livewire::actingAs($user)
        ->test(StepReview::class)
        ->call('store');

    $server = Server::query()->where('name', 'doks-prod')->first();
    expect($server)->not->toBeNull('Server was not created');
    expect($server->status)->toBe(Server::STATUS_READY);
    expect($server->health_status)->toBe(Server::HEALTH_REACHABLE);
    expect($server->meta['host_kind'])->toBe(Server::HOST_KIND_KUBERNETES);
    expect($server->meta['kubernetes']['cluster_name'])->toBe('prod-cluster');
    expect($server->meta['kubernetes']['namespace'])->toBe('apps');
    expect($server->meta['kubernetes']['provider'])->toBe('digitalocean');
});
/**
 * @param  array<string, mixed>  $payload
 */
function seedDraftAtStep(User $user, int $step, array $payload = []): void
{
    $defaults = [
        'mode' => 'provider',
        'type' => 'digitalocean',
        'name' => 'wizard-test',
        'install_profile' => 'laravel_app',
        'server_role' => 'application',
        'webserver' => 'nginx',
        'php_version' => '8.3',
        'database' => 'mysql84',
        'cache_service' => 'redis',
    ];

    ServerCreateDraft::query()->updateOrCreate(
        [
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
        ],
        [
            'step' => $step,
            'payload' => array_merge($defaults, $payload),
        ],
    );
}
function userWithOrganization(): User
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $user->setRelation('currentOrganization', $organization);
    session(['current_organization_id' => $organization->id]);

    return $user;
}
