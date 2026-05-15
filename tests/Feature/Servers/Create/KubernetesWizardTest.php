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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
