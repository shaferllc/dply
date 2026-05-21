<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sites;

use App\Livewire\Sites\DeployHooks;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DeployHooksTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Site} */
    private function ownerAndSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        return [$user, $site];
    }

    public function test_it_adds_a_deploy_hook(): void
    {
        [$user, $site] = $this->ownerAndSite();

        Livewire::actingAs($user)
            ->test(DeployHooks::class, ['site' => $site])
            ->set('newPhase', SiteDeployHook::PHASE_AFTER_CLONE)
            ->set('newScript', 'npm ci && npm run build')
            ->set('newTimeout', 300)
            ->call('addHook')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('site_deploy_hooks', [
            'site_id' => $site->id,
            'phase' => 'after_clone',
            'script' => 'npm ci && npm run build',
            'timeout_seconds' => 300,
        ]);
    }

    public function test_it_validates_the_hook_script_is_present(): void
    {
        [$user, $site] = $this->ownerAndSite();

        Livewire::actingAs($user)
            ->test(DeployHooks::class, ['site' => $site])
            ->set('newScript', '')
            ->call('addHook')
            ->assertHasErrors('newScript');

        $this->assertDatabaseCount('site_deploy_hooks', 0);
    }

    public function test_it_removes_a_deploy_hook(): void
    {
        [$user, $site] = $this->ownerAndSite();
        $hook = SiteDeployHook::query()->create([
            'site_id' => $site->id,
            'phase' => SiteDeployHook::PHASE_AFTER_ACTIVATE,
            'sort_order' => 0,
            'script' => 'curl -fsS https://example.test/warm',
            'timeout_seconds' => 60,
        ]);

        Livewire::actingAs($user)
            ->test(DeployHooks::class, ['site' => $site])
            ->assertSee('curl -fsS')
            ->call('deleteHook', $hook->id);

        $this->assertDatabaseMissing('site_deploy_hooks', ['id' => $hook->id]);
    }
}
