<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerProvisionStepRun;
use App\Models\User;
use App\Services\Servers\ProvisionStepEtaService;
use App\Support\Servers\ProvisionStepSnapshots;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProvisionStepEtaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function freshOrgWithServer(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        return [$org, $server];
    }

    private function recordStepRun(Organization $org, Server $server, string $label, int $seconds, bool $resumed = false): void
    {
        ServerProvisionStepRun::query()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'label_hash' => ProvisionStepSnapshots::keyForLabel($label),
            'label' => $label,
            'started_at' => now()->subSeconds($seconds),
            'completed_at' => now(),
            'duration_seconds' => $seconds,
            'resumed' => $resumed,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('server_provision.step_eta_min_samples', 3);
        config()->set('server_provision.step_eta_cache_ttl_seconds', 0);
    }

    public function test_returns_null_when_no_data_exists(): void
    {
        [$org] = $this->freshOrgWithServer();
        $hash = ProvisionStepSnapshots::keyForLabel('Installing MySQL');

        $this->assertNull(app(ProvisionStepEtaService::class)->averageForLabel($hash, $org));
    }

    public function test_returns_null_when_below_min_samples_threshold(): void
    {
        [$org, $server] = $this->freshOrgWithServer();

        $this->recordStepRun($org, $server, 'Installing MySQL', 60);
        $this->recordStepRun($org, $server, 'Installing MySQL', 90);

        $hash = ProvisionStepSnapshots::keyForLabel('Installing MySQL');

        // Only 2 runs, threshold is 3 → no average surfaced.
        $this->assertNull(app(ProvisionStepEtaService::class)->averageForLabel($hash, $org));
    }

    public function test_returns_average_once_threshold_clears(): void
    {
        [$org, $server] = $this->freshOrgWithServer();

        $this->recordStepRun($org, $server, 'Installing MySQL', 60);
        $this->recordStepRun($org, $server, 'Installing MySQL', 90);
        $this->recordStepRun($org, $server, 'Installing MySQL', 120);

        $hash = ProvisionStepSnapshots::keyForLabel('Installing MySQL');
        $eta = app(ProvisionStepEtaService::class)->averageForLabel($hash, $org);

        $this->assertNotNull($eta);
        $this->assertSame(90, $eta['seconds']); // (60+90+120)/3 = 90
        $this->assertSame(3, $eta['samples']);
    }

    public function test_resumed_rows_are_excluded_from_average(): void
    {
        [$org, $server] = $this->freshOrgWithServer();

        $this->recordStepRun($org, $server, 'Installing MySQL', 100);
        $this->recordStepRun($org, $server, 'Installing MySQL', 100);
        $this->recordStepRun($org, $server, 'Installing MySQL', 100);
        // Resumed-skip rows must not drag the mean toward zero.
        $this->recordStepRun($org, $server, 'Installing MySQL', 0, resumed: true);
        $this->recordStepRun($org, $server, 'Installing MySQL', 0, resumed: true);

        $hash = ProvisionStepSnapshots::keyForLabel('Installing MySQL');
        $eta = app(ProvisionStepEtaService::class)->averageForLabel($hash, $org);

        $this->assertNotNull($eta);
        $this->assertSame(100, $eta['seconds']);
        $this->assertSame(3, $eta['samples']); // resumed rows uncounted
    }

    public function test_averages_for_labels_bulk_resolves_keys(): void
    {
        [$org, $server] = $this->freshOrgWithServer();

        foreach (['Installing MySQL', 'Installing PHP 8.4', 'Installing Redis'] as $label) {
            for ($i = 0; $i < 3; $i++) {
                $this->recordStepRun($org, $server, $label, ($i + 1) * 30); // 30, 60, 90 → avg 60
            }
        }

        $hashes = [
            ProvisionStepSnapshots::keyForLabel('Installing MySQL'),
            ProvisionStepSnapshots::keyForLabel('Installing PHP 8.4'),
            ProvisionStepSnapshots::keyForLabel('Installing Redis'),
            ProvisionStepSnapshots::keyForLabel('Never recorded'), // returns no row
        ];

        $out = app(ProvisionStepEtaService::class)->averagesForLabels($hashes, $org);

        $this->assertCount(3, $out);
        foreach (['Installing MySQL', 'Installing PHP 8.4', 'Installing Redis'] as $label) {
            $hash = ProvisionStepSnapshots::keyForLabel($label);
            $this->assertArrayHasKey($hash, $out);
            $this->assertSame(60, $out[$hash]['seconds']);
            $this->assertSame(3, $out[$hash]['samples']);
        }
        $this->assertArrayNotHasKey(
            ProvisionStepSnapshots::keyForLabel('Never recorded'),
            $out,
        );
    }

    public function test_organization_scope_isolates_averages(): void
    {
        [$orgA, $serverA] = $this->freshOrgWithServer();
        [$orgB, $serverB] = $this->freshOrgWithServer();

        // Org A averages 60s.
        for ($i = 0; $i < 3; $i++) {
            $this->recordStepRun($orgA, $serverA, 'Installing MySQL', 60);
        }
        // Org B averages 200s.
        for ($i = 0; $i < 3; $i++) {
            $this->recordStepRun($orgB, $serverB, 'Installing MySQL', 200);
        }

        $hash = ProvisionStepSnapshots::keyForLabel('Installing MySQL');
        $service = app(ProvisionStepEtaService::class);

        $this->assertSame(60, $service->averageForLabel($hash, $orgA)['seconds']);
        $this->assertSame(200, $service->averageForLabel($hash, $orgB)['seconds']);
    }

    public function test_returns_null_when_organization_argument_is_null(): void
    {
        $hash = ProvisionStepSnapshots::keyForLabel('Installing MySQL');

        $this->assertNull(app(ProvisionStepEtaService::class)->averageForLabel($hash, null));
        $this->assertSame([], app(ProvisionStepEtaService::class)->averagesForLabels([$hash], null));
    }
}
