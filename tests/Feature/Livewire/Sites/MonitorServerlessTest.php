<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Sites;

use App\Livewire\Sites\Monitor;
use App\Models\FunctionInvocation;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;
use App\Models\User;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class MonitorServerlessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Server, 2: Site} */
    private function makeFunctionsSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_FUNCTIONS_ACTIVE,
            'meta' => ['runtime_profile' => 'digitalocean_functions_web', 'serverless' => []],
        ]);

        return [$user, $server, $site];
    }

    public function test_function_monitor_renders_the_activity_section(): void
    {
        [$user, $server, $site] = $this->makeFunctionsSite();

        Livewire::actingAs($user)
            ->test(Monitor::class, ['server' => $server, 'site' => $site])
            ->assertSee('Function activity')
            // No invocations yet — the empty state, not the cards.
            ->assertSee('No invocations in this window yet');
    }

    public function test_activity_section_reflects_recorded_invocations(): void
    {
        [$user, $server, $site] = $this->makeFunctionsSite();
        foreach ([100, 200, 300] as $i => $duration) {
            FunctionInvocation::query()->create([
                'site_id' => $site->id,
                'source' => FunctionInvocation::SOURCE_WEB,
                'method' => 'GET',
                'path' => '/',
                'status_code' => 200,
                'success' => true,
                'duration_ms' => $duration,
                'cold' => $i === 0,
                'log_lines' => [],
                'created_at' => now()->subMinutes($i * 5),
            ]);
        }

        Livewire::actingAs($user)
            ->test(Monitor::class, ['server' => $server, 'site' => $site])
            ->assertSee('Function activity')
            ->assertDontSee('No invocations in this window yet')
            ->assertSee('Invocations')
            ->assertSee('p95 duration');
    }

    public function test_set_stats_range_rejects_unknown_ranges(): void
    {
        [$user, $server, $site] = $this->makeFunctionsSite();

        Livewire::actingAs($user)
            ->test(Monitor::class, ['server' => $server, 'site' => $site])
            ->call('setStatsRange', '1h')
            ->assertSet('statsRange', '1h')
            ->call('setStatsRange', 'bogus')
            ->assertSet('statsRange', '24h');
    }

    public function test_a_function_without_a_monitor_gets_a_homepage_check(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeFunctionsSite();
        // Simulate a function created before the Site::created uptime hook.
        SiteUptimeMonitor::query()->where('site_id', $site->id)->delete();

        Livewire::actingAs($user)->test(Monitor::class, ['server' => $server, 'site' => $site]);

        $monitor = SiteUptimeMonitor::query()->where('site_id', $site->id)->first();
        $this->assertNotNull($monitor);
        $this->assertSame('Homepage check', $monitor->label);
        $this->assertContains(
            $monitor->probe_region,
            array_keys(config('site_uptime.probe_regions')),
        );

        // Idempotent — a second visit must not add a duplicate.
        Livewire::actingAs($user)->test(Monitor::class, ['server' => $server, 'site' => $site]);
        $this->assertSame(1, SiteUptimeMonitor::query()->where('site_id', $site->id)->count());
    }

    public function test_the_uptime_resolver_finds_a_function_url(): void
    {
        [, , $site] = $this->makeFunctionsSite();
        $site->update(['meta' => array_merge((array) $site->meta, [
            'serverless' => ['action_url' => 'https://faas.example/api/v1/web/ns/default/fn'],
        ])]);

        $url = app(SiteUptimeCheckUrlResolver::class)->resolveBaseUrl($site->fresh());

        // A function resolves a public URL — it no longer reports "no URL".
        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://', (string) $url);
    }

    public function test_a_vm_site_monitor_has_no_function_section(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['webserver' => 'nginx'],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        Livewire::actingAs($user)
            ->test(Monitor::class, ['server' => $server, 'site' => $site])
            ->assertDontSee('Function activity');
    }
}
