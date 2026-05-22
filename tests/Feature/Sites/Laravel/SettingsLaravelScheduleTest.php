<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\Laravel;

use App\Enums\SiteType;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * PR 11 — Laravel Schedule sub-tab integration via the Artisan
 * service from PR 1+2. Inline-rendered inside the existing laravel-stack
 * section; the schedule:list call is on the INSTANT allowlist so it
 * runs synchronously.
 */
class SettingsLaravelScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeLaravelSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);
        session(['current_organization_id' => $org->id]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Php,
            'document_root' => '/home/dply/app/current',
            // Tee runtime app detection toward Laravel so the
            // laravel-stack section actually renders.
            'meta' => [
                'vm_runtime' => ['detected' => ['framework' => 'laravel', 'language' => 'php']],
            ],
        ]);

        return [$user, $server, $site];
    }

    public function test_load_schedule_runs_artisan_and_populates_entries(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        $scheduleJson = json_encode([
            ['command' => 'inspire', 'description' => 'Display an inspiring quote', 'expression' => '0 9 * * *', 'next_due' => '2026-05-04 09:00:00'],
            ['command' => 'queue:prune-batches', 'description' => 'Prune old batches', 'expression' => '0 0 * * *', 'next_due' => '2026-05-04 00:00:00'],
        ]);

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBashWithOutputCallback')
            ->once()
            ->withArgs(function ($s, string $name, string $bash, callable $cb) use ($scheduleJson) {
                $this->assertStringContainsString('php artisan schedule:list', $bash);
                // schedule:list is sync via the INSTANT allowlist; the
                // RemoteCli streaming callback must be driven so stdout
                // populates.
                $cb('out', $scheduleJson);

                return true;
            })
            ->andReturn(new ProcessOutput($scheduleJson, 0, false));
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        $component = Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'schedule')
            ->call('loadLaravelSchedule')
            ->assertSet('laravelScheduleLoaded', true)
            ->assertCount('laravelScheduleEntries', 2);

        // Asserting against the parsed property rather than rendered
        // HTML — the parent SiteSettings view is huge and brittle to
        // assertSee from a sub-partial inside a Laravel-section gate.
        $entries = $component->get('laravelScheduleEntries');
        $this->assertSame('inspire', $entries[0]['command']);
        $this->assertSame('queue:prune-batches', $entries[1]['command']);
        $this->assertSame('0 9 * * *', $entries[0]['expression']);
    }

    public function test_load_schedule_handles_empty_kernel_gracefully(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBashWithOutputCallback')
            ->withArgs(function ($s, $name, $bash, callable $cb) {
                $cb('out', '[]');

                return true;
            })
            ->andReturn(new ProcessOutput('[]', 0, false));
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'schedule')
            ->call('loadLaravelSchedule')
            ->assertSet('laravelScheduleEntries', [])
            ->assertSet('laravelScheduleLoaded', true);
    }

    public function test_load_schedule_handles_malformed_output_without_throwing(): void
    {
        [$user, $server, $site] = $this->makeLaravelSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBashWithOutputCallback')
            ->withArgs(function ($s, $name, $bash, callable $cb) {
                $cb('out', "this is not json\n");

                return true;
            })
            ->andReturn(new ProcessOutput('this is not json', 0, false));
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'laravel-stack'])
            ->set('laravel_tab', 'schedule')
            ->call('loadLaravelSchedule')
            ->assertSet('laravelScheduleEntries', [])
            ->assertSet('laravelScheduleLoaded', true);
    }
}
