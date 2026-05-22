<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunLaravelScaffoldJob;
use App\Jobs\RunWordPressScaffoldJob;
use App\Livewire\Sites\Create as SitesCreate;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Site Create scaffold-mode wizard surface (PR 4 view + storeScaffold action).
 *
 * The scaffold pipeline itself ships in PR 5 (Laravel) / PR 6 (WordPress);
 * these tests verify the wizard branch creates a Site row in
 * STATUS_SCAFFOLDING with the right framework / admin email metadata so
 * the journey UI in PR 7 has something to render against.
 */
class SiteCreateScaffoldModeTest extends TestCase
{
    use RefreshDatabase;

    private function userWithOrgAndServer(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        return [$user, $server];
    }

    public function test_default_mode_is_import_when_flag_off(): void
    {
        config(['dply.scaffold_v1_enabled' => false]);
        [$user, $server] = $this->userWithOrgAndServer();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->assertSet('form.mode', 'import')
            // Mode toggle hidden when the flag is off — clean upgrade path
            // for installs that haven't enabled scaffolding yet.
            ->assertDontSee('Scaffold a new app');
    }

    public function test_mode_toggle_renders_when_flag_on(): void
    {
        config(['dply.scaffold_v1_enabled' => true]);
        [$user, $server] = $this->userWithOrgAndServer();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->assertSee('Scaffold a new app')
            ->assertSee('Import an existing repo');
    }

    public function test_choosing_scaffold_mode_swaps_panels(): void
    {
        config(['dply.scaffold_v1_enabled' => true]);
        [$user, $server] = $this->userWithOrgAndServer();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->call('chooseScaffoldMode')
            ->assertSet('form.mode', 'scaffold')
            ->assertSee('Pick a starter')
            ->assertSee('Laravel app')
            ->assertSee('WordPress');
    }

    public function test_choosing_scaffold_framework_persists(): void
    {
        config(['dply.scaffold_v1_enabled' => true]);
        [$user, $server] = $this->userWithOrgAndServer();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->call('chooseScaffoldMode')
            ->call('chooseScaffoldFramework', 'laravel')
            ->assertSet('form.scaffold_framework', 'laravel')
            ->call('chooseScaffoldFramework', 'wordpress')
            ->assertSet('form.scaffold_framework', 'wordpress');
    }

    public function test_invalid_framework_is_ignored(): void
    {
        config(['dply.scaffold_v1_enabled' => true]);
        [$user, $server] = $this->userWithOrgAndServer();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->call('chooseScaffoldFramework', 'symfony')
            ->assertSet('form.scaffold_framework', '');
    }

    public function test_chooseimport_clears_scaffold_state(): void
    {
        config(['dply.scaffold_v1_enabled' => true]);
        [$user, $server] = $this->userWithOrgAndServer();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->call('chooseScaffoldMode')
            ->call('chooseScaffoldFramework', 'wordpress')
            ->set('form.scaffold_admin_email', 'me@example.com')
            ->call('chooseImportMode')
            ->assertSet('form.mode', 'import')
            ->assertSet('form.scaffold_framework', '')
            ->assertSet('form.scaffold_admin_email', '');
    }

    public function test_storescaffold_creates_site_in_scaffolding_status(): void
    {
        Bus::fake(); // Prevent the WP pipeline job from running inline
        config(['dply.scaffold_v1_enabled' => true]);
        [$user, $server] = $this->userWithOrgAndServer();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->call('chooseScaffoldMode')
            ->call('chooseScaffoldFramework', 'wordpress')
            ->set('form.name', 'My WP Blog')
            ->set('form.scaffold_admin_email', 'admin@example.com')
            ->call('storeScaffold');

        $site = Site::query()->sole();
        $this->assertSame('My WP Blog', $site->name);
        $this->assertSame('my-wp-blog', $site->slug);
        $this->assertSame(Site::STATUS_SCAFFOLDING, $site->status);
        $this->assertSame('wordpress', $site->meta['scaffold']['framework']);
        $this->assertSame('admin@example.com', $site->meta['scaffold']['admin_email']);
        $this->assertSame($user->id, $site->meta['scaffold']['requested_by_user_id']);
        $this->assertNull($site->meta['scaffold']['requested_hostname']);

        Bus::assertDispatched(RunWordPressScaffoldJob::class,
            fn ($job) => $job->siteId === $site->id);
    }

    public function test_storescaffold_records_optional_hostname(): void
    {
        Bus::fake();
        config(['dply.scaffold_v1_enabled' => true]);
        [$user, $server] = $this->userWithOrgAndServer();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->call('chooseScaffoldMode')
            ->call('chooseScaffoldFramework', 'laravel')
            ->set('form.name', 'My Laravel App')
            ->set('form.scaffold_admin_email', 'me@example.com')
            ->set('form.primary_hostname', 'app.example.com')
            ->call('storeScaffold');

        $site = Site::query()->sole();
        $this->assertSame('app.example.com', $site->meta['scaffold']['requested_hostname']);

        Bus::assertDispatched(RunLaravelScaffoldJob::class,
            fn ($job) => $job->siteId === $site->id);
    }

    public function test_storescaffold_validates_required_fields(): void
    {
        config(['dply.scaffold_v1_enabled' => true]);
        [$user, $server] = $this->userWithOrgAndServer();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->call('chooseScaffoldMode')
            ->call('storeScaffold')
            ->assertHasErrors(['form.name', 'form.scaffold_framework', 'form.scaffold_admin_email']);

        $this->assertSame(0, Site::query()->count());
    }

    public function test_storescaffold_blocks_when_feature_flag_off(): void
    {
        config(['dply.scaffold_v1_enabled' => false]);
        [$user, $server] = $this->userWithOrgAndServer();

        Livewire::actingAs($user)
            ->test(SitesCreate::class, ['server' => $server])
            ->set('form.mode', 'scaffold')
            ->set('form.scaffold_framework', 'laravel')
            ->set('form.name', 'wat')
            ->set('form.scaffold_admin_email', 'me@example.com')
            ->call('storeScaffold')
            ->assertHasErrors('form.mode');

        $this->assertSame(0, Site::query()->count());
    }
}
