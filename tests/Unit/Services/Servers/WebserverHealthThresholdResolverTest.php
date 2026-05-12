<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Models\WebserverHealthThreshold;
use App\Services\Servers\WebserverHealthThresholdResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validates the resolve() precedence chain documented on the migration:
 *   server+engine > server > org+engine > org > config fallback.
 */
class WebserverHealthThresholdResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(): Server
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_organization_id' => $org->id]);

        return Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
    }

    public function test_falls_back_to_config_when_no_overrides(): void
    {
        $server = $this->makeServer();

        $threshold = app(WebserverHealthThresholdResolver::class)
            ->resolve($server, 'nginx', 'errors_5xx_per_min');

        $this->assertNotNull($threshold);
        $this->assertSame('gt', $threshold['comparator']);
        $this->assertSame(10.0, $threshold['value']); // From config/server_metrics.php
        $this->assertSame('warning', $threshold['severity']);
    }

    public function test_org_default_wins_over_config_fallback(): void
    {
        $server = $this->makeServer();
        WebserverHealthThreshold::query()->create([
            'organization_id' => $server->organization_id,
            'server_id' => null,
            'engine' => null,
            'metric' => 'errors_5xx_per_min',
            'comparator' => 'gt',
            'value' => 99,
            'severity' => 'critical',
        ]);

        $threshold = app(WebserverHealthThresholdResolver::class)
            ->resolve($server, 'nginx', 'errors_5xx_per_min');

        $this->assertSame(99.0, $threshold['value']);
        $this->assertSame('critical', $threshold['severity']);
    }

    public function test_org_engine_specific_wins_over_org_default(): void
    {
        $server = $this->makeServer();
        WebserverHealthThreshold::query()->create([
            'organization_id' => $server->organization_id,
            'server_id' => null,
            'engine' => null,
            'metric' => 'errors_5xx_per_min',
            'comparator' => 'gt',
            'value' => 99,
            'severity' => 'warning',
        ]);
        WebserverHealthThreshold::query()->create([
            'organization_id' => $server->organization_id,
            'server_id' => null,
            'engine' => 'nginx',
            'metric' => 'errors_5xx_per_min',
            'comparator' => 'gt',
            'value' => 5,
            'severity' => 'critical',
        ]);

        $threshold = app(WebserverHealthThresholdResolver::class)
            ->resolve($server, 'nginx', 'errors_5xx_per_min');

        $this->assertSame(5.0, $threshold['value']);
        $this->assertSame('critical', $threshold['severity']);

        // Other engines still use org default.
        $caddyThreshold = app(WebserverHealthThresholdResolver::class)
            ->resolve($server, 'caddy', 'errors_5xx_per_min');
        $this->assertSame(99.0, $caddyThreshold['value']);
    }

    public function test_server_override_wins_over_org_default(): void
    {
        $server = $this->makeServer();
        WebserverHealthThreshold::query()->create([
            'organization_id' => $server->organization_id,
            'server_id' => null,
            'engine' => null,
            'metric' => 'errors_5xx_per_min',
            'comparator' => 'gt',
            'value' => 50,
            'severity' => 'warning',
        ]);
        WebserverHealthThreshold::query()->create([
            'organization_id' => null,
            'server_id' => $server->id,
            'engine' => null,
            'metric' => 'errors_5xx_per_min',
            'comparator' => 'gt',
            'value' => 1,
            'severity' => 'critical',
        ]);

        $threshold = app(WebserverHealthThresholdResolver::class)
            ->resolve($server, 'nginx', 'errors_5xx_per_min');

        $this->assertSame(1.0, $threshold['value']);
        $this->assertSame('critical', $threshold['severity']);
    }

    public function test_server_engine_specific_wins_over_server_default(): void
    {
        $server = $this->makeServer();
        WebserverHealthThreshold::query()->create([
            'server_id' => $server->id,
            'engine' => null,
            'metric' => 'errors_5xx_per_min',
            'comparator' => 'gt',
            'value' => 50,
            'severity' => 'warning',
        ]);
        WebserverHealthThreshold::query()->create([
            'server_id' => $server->id,
            'engine' => 'caddy',
            'metric' => 'errors_5xx_per_min',
            'comparator' => 'gt',
            'value' => 3,
            'severity' => 'critical',
        ]);

        $caddy = app(WebserverHealthThresholdResolver::class)
            ->resolve($server, 'caddy', 'errors_5xx_per_min');
        $this->assertSame(3.0, $caddy['value']);

        $nginx = app(WebserverHealthThresholdResolver::class)
            ->resolve($server, 'nginx', 'errors_5xx_per_min');
        $this->assertSame(50.0, $nginx['value']);
    }

    public function test_trips_compares_correctly(): void
    {
        $resolver = app(WebserverHealthThresholdResolver::class);

        $this->assertTrue($resolver->trips(['comparator' => 'gt', 'value' => 10.0, 'severity' => 'warning'], 11.0));
        $this->assertFalse($resolver->trips(['comparator' => 'gt', 'value' => 10.0, 'severity' => 'warning'], 10.0));
        $this->assertTrue($resolver->trips(['comparator' => 'gte', 'value' => 10.0, 'severity' => 'warning'], 10.0));
        $this->assertTrue($resolver->trips(['comparator' => 'lt', 'value' => 5.0, 'severity' => 'warning'], 4.0));
        $this->assertFalse($resolver->trips(null, 9999));
    }
}
