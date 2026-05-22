<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Enums\SiteType;
use App\Jobs\ProvisionCustomSiteJob;
use App\Livewire\Sites\Create as SiteCreate;
use App\Livewire\Sites\CreateCustom;
use App\Models\Organization;
use App\Models\Script;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

final class CreateCustomTest extends TestCase
{
    use RefreshDatabase;

    public function test_vm_host_shows_custom_entry_point_on_create_page(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->vmServer($user);

        Livewire::actingAs($user)
            ->test(SiteCreate::class, ['server' => $server])
            ->assertSee('Create a Custom site');
    }

    public function test_docker_host_hides_custom_entry_point(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->dockerServer($user);

        Livewire::actingAs($user)
            ->test(SiteCreate::class, ['server' => $server])
            ->assertDontSee('Create a Custom site');
    }

    public function test_creating_custom_site_with_repo_persists_git_mode(): void
    {
        Bus::fake();
        $user = $this->userWithOrganization();
        $server = $this->vmServer($user);

        Livewire::actingAs($user)
            ->test(CreateCustom::class, ['server' => $server])
            ->set('name', 'worker-queue')
            ->set('git_repository_url', 'git@github.com:me/worker.git')
            ->set('git_branch', 'main')
            ->call('store')
            ->assertHasNoErrors();

        $site = Site::query()->where('server_id', $server->id)->firstOrFail();

        $this->assertSame(SiteType::Custom, $site->type);
        $this->assertSame('worker-queue', $site->name);
        $this->assertSame('git@github.com:me/worker.git', $site->git_repository_url);
        $this->assertSame('main', $site->git_branch);
        $this->assertSame(Site::STATUS_PENDING, $site->status);
        $this->assertNotNull($site->deploy_script_id);
        $this->assertSame('simple', $site->deploy_strategy);

        $script = Script::query()->whereKey($site->deploy_script_id)->firstOrFail();
        $this->assertSame('site:custom_auto', $script->source);

        Bus::assertDispatched(ProvisionCustomSiteJob::class);
    }

    public function test_creating_custom_site_without_repo_persists_no_repo_mode(): void
    {
        Bus::fake();
        $user = $this->userWithOrganization();
        $server = $this->vmServer($user);

        Livewire::actingAs($user)
            ->test(CreateCustom::class, ['server' => $server])
            ->set('name', 'ci-target')
            ->set('git_repository_url', '')
            ->call('store')
            ->assertHasNoErrors();

        $site = Site::query()->where('server_id', $server->id)->firstOrFail();
        $this->assertSame(SiteType::Custom, $site->type);
        $this->assertNull($site->git_repository_url);
        $this->assertNull($site->git_branch);

        Bus::assertDispatched(ProvisionCustomSiteJob::class);
    }

    public function test_non_vm_host_rejects_custom_create(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->dockerServer($user);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        Livewire::actingAs($user)
            ->test(CreateCustom::class, ['server' => $server]);
    }

    private function vmServer(User $user): Server
    {
        return Server::factory()->ready()->create([
            'organization_id' => $user->currentOrganization()->id,
            'user_id' => $user->id,
            'meta' => ['host_kind' => Server::HOST_KIND_VM, 'webserver' => 'nginx'],
        ]);
    }

    private function dockerServer(User $user): Server
    {
        return Server::factory()->ready()->create([
            'organization_id' => $user->currentOrganization()->id,
            'user_id' => $user->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DOCKER],
        ]);
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
