<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\Create;

use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Create\StepReview;
use App\Livewire\Servers\Create\StepWhat;
use App\Livewire\Servers\Create\StepWhere;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Models\User;
use App\Jobs\PollDoksClusterStatusJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The container-flow inversion adds host_kind=kubernetes to the
 * /servers/create wizard so users register a managed DOKS cluster
 * as a server, then add containers to it. This test walks the K8s
 * happy path end-to-end and asserts the resulting Server row.
 */
final class KubernetesWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_choosing_kubernetes_host_kind_clears_type_for_provider_pick(): void
    {
        // Choosing the K8s host-kind tile sets provider_host_kind=kubernetes
        // but does NOT pin form.type — the user must then click a provider
        // tile (DO or AWS) which suffixes form.type to {provider}_kubernetes.
        $user = $this->userWithOrganization();
        $this->seedDraftAtStep($user, step: 2);

        Livewire::actingAs($user)
            ->test(StepWhere::class)
            ->call('chooseProviderHostKind', 'kubernetes')
            ->assertSet('form.provider_host_kind', 'kubernetes')
            ->assertSet('form.type', '');
    }

    public function test_choosing_digitalocean_provider_for_kubernetes_pins_form_type_to_digitalocean_kubernetes(): void
    {
        $user = $this->userWithOrganization();
        $this->seedDraftAtStep($user, step: 2);

        Livewire::actingAs($user)
            ->test(StepWhere::class)
            ->call('chooseProviderHostKind', 'kubernetes')
            ->call('chooseProvider', 'digitalocean')
            ->assertSet('form.provider_host_kind', 'kubernetes')
            ->assertSet('form.type', 'digitalocean_kubernetes');
    }

    public function test_step_where_validates_without_region_or_size_when_kubernetes(): void
    {
        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 2);

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
    }

    public function test_step_what_lists_clusters_from_digitalocean_api(): void
    {
        Cache::flush();
        Http::fake([
            'api.digitalocean.com/v2/kubernetes/clusters*' => Http::response([
                'kubernetes_clusters' => [
                    ['id' => 'abc-1', 'name' => 'prod-cluster', 'region' => 'nyc3'],
                    ['id' => 'def-2', 'name' => 'staging-cluster', 'region' => 'sfo3'],
                ],
            ], 200),
        ]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 3, payload: [
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
    }

    public function test_step_what_auto_selects_cluster_when_account_has_exactly_one(): void
    {
        Cache::flush();
        Http::fake([
            'api.digitalocean.com/v2/kubernetes/clusters*' => Http::response([
                'kubernetes_clusters' => [
                    ['id' => 'only-1', 'name' => 'only-cluster', 'region' => 'nyc3'],
                ],
            ], 200),
        ]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 3, payload: [
            'mode' => 'provider',
            'type' => 'digitalocean_kubernetes',
            'provider_host_kind' => 'kubernetes',
            'provider_credential_id' => (string) $credential->id,
            'name' => 'doks-test',
        ]);

        Livewire::actingAs($user)
            ->test(StepWhat::class)
            ->assertSet('form.do_kubernetes_cluster_name', 'only-cluster');
    }

    public function test_step_what_does_not_auto_select_when_account_has_multiple_clusters(): void
    {
        Cache::flush();
        Http::fake([
            'api.digitalocean.com/v2/kubernetes/clusters*' => Http::response([
                'kubernetes_clusters' => [
                    ['id' => 'a', 'name' => 'prod', 'region' => 'nyc3'],
                    ['id' => 'b', 'name' => 'staging', 'region' => 'sfo3'],
                ],
            ], 200),
        ]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 3, payload: [
            'mode' => 'provider',
            'type' => 'digitalocean_kubernetes',
            'provider_host_kind' => 'kubernetes',
            'provider_credential_id' => (string) $credential->id,
            'name' => 'doks-test',
        ]);

        Livewire::actingAs($user)
            ->test(StepWhat::class)
            ->assertSet('form.do_kubernetes_cluster_name', '');
    }

    public function test_step_what_shows_empty_state_when_account_has_no_clusters(): void
    {
        Cache::flush();
        Http::fake([
            'api.digitalocean.com/v2/kubernetes/clusters*' => Http::response([
                'kubernetes_clusters' => [],
            ], 200),
        ]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 3, payload: [
            'mode' => 'provider',
            'type' => 'digitalocean_kubernetes',
            'provider_host_kind' => 'kubernetes',
            'provider_credential_id' => (string) $credential->id,
            'name' => 'doks-test',
        ]);

        Livewire::actingAs($user)
            ->test(StepWhat::class)
            ->assertSee('No managed clusters found in this account.');
    }

    public function test_step_review_shows_billing_disclosure_instead_of_cost_preview(): void
    {
        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 4, payload: [
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
    }

    public function test_step_where_does_not_block_on_missing_cluster_name_for_kubernetes(): void
    {
        Cache::flush();
        Http::fake(['api.digitalocean.com/v2/*' => Http::response([
            'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
            'options' => ['versions' => []],
        ], 200)]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 2, payload: [
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
        $this->assertNotNull($clusterCheck, 'expected a cluster-name check to be surfaced');
        $this->assertFalse($clusterCheck['blocking'], 'cluster-name check should NOT block on StepWhere');
        $this->assertSame('warning', $clusterCheck['severity']);
        // No blocking issues from cluster fields specifically.
        $this->assertSame(0, $blockingChecks->where('field', 'do_kubernetes_cluster_name')->count());
    }

    public function test_step_review_still_blocks_on_missing_cluster_name_for_kubernetes(): void
    {
        Cache::flush();
        Http::fake(['api.digitalocean.com/v2/*' => Http::response([
            'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
            'options' => ['versions' => []],
        ], 200)]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 4, payload: [
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
        $this->assertNotNull($clusterCheck);
        $this->assertTrue($clusterCheck['blocking'], 'cluster-name check should block on StepReview');
        $this->assertSame('error', $clusterCheck['severity']);
    }

    public function test_step_what_seeds_default_cluster_name_when_create_new_active(): void
    {
        Cache::flush();
        Http::fake(['api.digitalocean.com/v2/*' => Http::response([
            'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
            'options' => ['versions' => []],
        ], 200)]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 3, payload: [
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

        $this->assertMatchesRegularExpression('/^dply-cluster-[0-9a-f]{6}$/', $name);
    }

    public function test_step_what_regenerate_button_rolls_a_new_cluster_name(): void
    {
        Cache::flush();
        Http::fake(['api.digitalocean.com/v2/*' => Http::response([
            'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
            'options' => ['versions' => []],
        ], 200)]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 3, payload: [
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
        $this->assertMatchesRegularExpression('/^dply-cluster-[0-9a-f]{6}$/', $after);
    }

    public function test_step_what_does_not_clobber_user_edited_cluster_name(): void
    {
        Cache::flush();
        Http::fake(['api.digitalocean.com/v2/*' => Http::response([
            'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
            'options' => ['versions' => []],
        ], 200)]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 3, payload: [
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

        $this->assertSame('my-handpicked-name', $name, 'mount auto-default must not overwrite an existing name');
    }

    public function test_step_what_continue_button_disabled_when_no_cluster_picked_in_existing_mode(): void
    {
        Cache::flush();
        Http::fake(['api.digitalocean.com/v2/*' => Http::response([
            'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
            'options' => ['versions' => []],
        ], 200)]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 3, payload: [
            'mode' => 'provider',
            'type' => 'digitalocean_kubernetes',
            'provider_host_kind' => 'kubernetes',
            'provider_credential_id' => (string) $credential->id,
            'name' => 'doks-test',
            // existing mode (default) with no cluster picked → can't continue
        ]);

        $this->assertFalse(
            Livewire::actingAs($user)->test(StepWhat::class)->viewData('canContinue'),
            'Continue button should be disabled when existing-mode and no cluster picked',
        );
    }

    public function test_step_what_continue_button_enabled_once_create_new_fields_are_filled(): void
    {
        Cache::flush();
        Http::fake(['api.digitalocean.com/v2/*' => Http::response([
            'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
            'options' => ['versions' => []],
        ], 200)]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 3, payload: [
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

        $this->assertTrue(
            Livewire::actingAs($user)->test(StepWhat::class)->viewData('canContinue'),
            'Continue button should be enabled when all create-new fields are filled',
        );
    }

    public function test_step_what_renders_create_new_toggle_for_doks(): void
    {
        Cache::flush();
        Http::fake([
            'api.digitalocean.com/v2/kubernetes/clusters*' => Http::response(['kubernetes_clusters' => []], 200),
            'api.digitalocean.com/v2/regions*' => Http::response(['regions' => [['slug' => 'nyc3', 'name' => 'New York 3', 'available' => true]]], 200),
            'api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => [['slug' => 's-2vcpu-4gb', 'memory' => 4096, 'vcpus' => 2, 'disk' => 80, 'price_monthly' => 24.0, 'available' => true]]], 200),
            'api.digitalocean.com/v2/kubernetes/options*' => Http::response(['options' => ['versions' => [['slug' => '1.30.1-do.0', 'kubernetes_version' => '1.30.1']]]], 200),
        ]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 3, payload: [
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
    }

    public function test_step_what_switches_to_create_new_form_and_validates_fields(): void
    {
        Cache::flush();
        Http::fake([
            'api.digitalocean.com/v2/*' => Http::response([
                'kubernetes_clusters' => [], 'regions' => [], 'sizes' => [],
                'options' => ['versions' => []],
            ], 200),
        ]);

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 3, payload: [
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
    }

    public function test_servers_show_does_not_loop_for_kubernetes_provisioning_servers(): void
    {
        // Reproduces the "too many redirects" loop: previously servers.show
        // sent K8s PROVISIONING servers to the journey page, but the journey
        // component's bootWorkspace rejects non-VM hosts and bounces back to
        // servers.show. The route now short-circuits to overview for non-VM
        // hosts so the loop can't form.
        $user = $this->userWithOrganization();
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
    }

    public function test_storing_create_new_doks_calls_digitalocean_api_and_lands_provisioning(): void
    {
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

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 4, payload: [
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
        $this->assertNotNull($server);
        $this->assertSame(Server::STATUS_PROVISIONING, $server->status);
        $this->assertSame('fresh-cluster', $server->meta['kubernetes']['cluster_name']);
        $this->assertSame('new-cluster-id', $server->meta['kubernetes']['cluster_id']);
        $this->assertSame('nyc3', $server->meta['kubernetes']['region']);
        $this->assertTrue($server->meta['kubernetes']['provisioned_by_dply']);

        // Verify the DO create endpoint was actually called.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/kubernetes/clusters')
            && $request->method() === 'POST'
            && $request['name'] === 'fresh-cluster'
            && $request['node_pools'][0]['size'] === 's-2vcpu-4gb');

        // And the poller was queued so the server eventually flips to READY.
        Queue::assertPushed(PollDoksClusterStatusJob::class);
    }

    public function test_doks_node_size_picker_only_shows_kubernetes_eligible_sizes(): void
    {
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

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 3, payload: [
            'mode' => 'provider',
            'type' => 'digitalocean_kubernetes',
            'provider_host_kind' => 'kubernetes',
            'provider_credential_id' => (string) $credential->id,
            'name' => 'doks-test',
            'do_kubernetes_source' => 'new',
        ]);

        $sizes = Livewire::actingAs($user)->test(StepWhat::class)->viewData('kubernetesNodeSizes');
        $slugs = array_column($sizes, 'value');

        $this->assertSame(['s-2vcpu-4gb'], $slugs,
            'only sizes published by /kubernetes/options.sizes should be offered to the create-new picker');

        // Regions should also be filtered to the DOKS-eligible set.
        $regions = Livewire::actingAs($user)->test(StepWhat::class)->viewData('kubernetesRegions');
        $this->assertSame(['nyc3'], array_column($regions, 'value'));
    }

    public function test_create_new_with_empty_version_resolves_latest_from_options(): void
    {
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

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 4, payload: [
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

        $this->assertNotEmpty($sentBodies, 'no create-cluster POST captured');
        $this->assertSame('1.31.0-do.0', $sentBodies[0]['version'],
            'service should fall back to the newest published DOKS version slug when the form leaves it blank');
    }

    public function test_storing_create_new_doks_surfaces_api_failure_as_validation_error(): void
    {
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

        $user = $this->userWithOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 4, payload: [
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

        $this->assertNull(Server::query()->where('name', 'doks-dup')->first());
    }

    public function test_choosing_aws_provider_for_kubernetes_pins_form_type_to_aws_kubernetes(): void
    {
        $user = $this->userWithOrganization();
        $this->seedDraftAtStep($user, step: 2);

        Livewire::actingAs($user)
            ->test(StepWhere::class)
            ->call('chooseProviderHostKind', 'kubernetes')
            ->call('chooseProvider', 'aws')
            ->assertSet('form.provider_host_kind', 'kubernetes')
            ->assertSet('form.type', 'aws_kubernetes');
    }

    public function test_storing_aws_kubernetes_server_lands_status_ready_immediately(): void
    {
        // Store action now dispatches PollEksClusterStatusJob + calls
        // AwsEksService::getCluster for the cluster_id lookup. Fake the queue
        // so the poller doesn't run synchronously and overwrite state; the
        // getCluster call goes through the AWS SDK which we don't fake here
        // so we expect it to fail silently and the store to proceed without
        // a cluster_id (matching the production "AWS hiccup at register time"
        // graceful path).
        Queue::fake();

        $user = $this->userWithOrganization();
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
        $this->seedDraftAtStep($user, step: 4, payload: [
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
        $this->assertNotNull($server, 'EKS server was not created');
        $this->assertSame(Server::STATUS_READY, $server->status);
        $this->assertSame(Server::HEALTH_REACHABLE, $server->health_status);
        $this->assertSame(Server::HOST_KIND_KUBERNETES, $server->meta['host_kind']);
        $this->assertSame('aws', $server->meta['kubernetes']['provider']);
        $this->assertSame('prod-eks', $server->meta['kubernetes']['cluster_name']);
        $this->assertSame('apps', $server->meta['kubernetes']['namespace']);
        $this->assertSame('us-west-2', $server->meta['kubernetes']['region']);
    }

    public function test_storing_kubernetes_server_lands_status_ready_immediately(): void
    {
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

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);
        $this->seedDraftAtStep($user, step: 4, payload: [
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
        $this->assertNotNull($server, 'Server was not created');
        $this->assertSame(Server::STATUS_READY, $server->status);
        $this->assertSame(Server::HEALTH_REACHABLE, $server->health_status);
        $this->assertSame(Server::HOST_KIND_KUBERNETES, $server->meta['host_kind']);
        $this->assertSame('prod-cluster', $server->meta['kubernetes']['cluster_name']);
        $this->assertSame('apps', $server->meta['kubernetes']['namespace']);
        $this->assertSame('digitalocean', $server->meta['kubernetes']['provider']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function seedDraftAtStep(User $user, int $step, array $payload = []): void
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

    private function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        $user->setRelation('currentOrganization', $organization);
        session(['current_organization_id' => $organization->id]);

        return $user;
    }
}
