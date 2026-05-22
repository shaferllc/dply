<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers\ProvisionStepEtaServiceTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerProvisionStepRun;
use App\Models\User;
use App\Services\Servers\ProvisionStepEtaService;
use App\Support\Servers\ProvisionStepSnapshots;
use Illuminate\Support\Facades\Cache;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function freshOrgWithServer(): array
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
function recordStepRun(Organization $org, Server $server, string $label, int $seconds, bool $resumed = false): void
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
beforeEach(function () {
    Cache::flush();
    config()->set('server_provision.step_eta_min_samples', 3);
    config()->set('server_provision.step_eta_cache_ttl_seconds', 0);
});
test('returns null when no data exists', function () {
    [$org] = freshOrgWithServer();
    $hash = ProvisionStepSnapshots::keyForLabel('Installing MySQL');

    expect(app(ProvisionStepEtaService::class)->averageForLabel($hash, $org))->toBeNull();
});
test('returns null when below min samples threshold', function () {
    [$org, $server] = freshOrgWithServer();

    recordStepRun($org, $server, 'Installing MySQL', 60);
    recordStepRun($org, $server, 'Installing MySQL', 90);

    $hash = ProvisionStepSnapshots::keyForLabel('Installing MySQL');

    // Only 2 runs, threshold is 3 → no average surfaced.
    expect(app(ProvisionStepEtaService::class)->averageForLabel($hash, $org))->toBeNull();
});
test('returns average once threshold clears', function () {
    [$org, $server] = freshOrgWithServer();

    recordStepRun($org, $server, 'Installing MySQL', 60);
    recordStepRun($org, $server, 'Installing MySQL', 90);
    recordStepRun($org, $server, 'Installing MySQL', 120);

    $hash = ProvisionStepSnapshots::keyForLabel('Installing MySQL');
    $eta = app(ProvisionStepEtaService::class)->averageForLabel($hash, $org);

    expect($eta)->not->toBeNull();
    expect($eta['seconds'])->toBe(90);
    // (60+90+120)/3 = 90
    expect($eta['samples'])->toBe(3);
});
test('resumed rows are excluded from average', function () {
    [$org, $server] = freshOrgWithServer();

    recordStepRun($org, $server, 'Installing MySQL', 100);
    recordStepRun($org, $server, 'Installing MySQL', 100);
    recordStepRun($org, $server, 'Installing MySQL', 100);

    // Resumed-skip rows must not drag the mean toward zero.
    recordStepRun($org, $server, 'Installing MySQL', 0, resumed: true);
    recordStepRun($org, $server, 'Installing MySQL', 0, resumed: true);

    $hash = ProvisionStepSnapshots::keyForLabel('Installing MySQL');
    $eta = app(ProvisionStepEtaService::class)->averageForLabel($hash, $org);

    expect($eta)->not->toBeNull();
    expect($eta['seconds'])->toBe(100);
    expect($eta['samples'])->toBe(3);
    // resumed rows uncounted
});
test('averages for labels bulk resolves keys', function () {
    [$org, $server] = freshOrgWithServer();

    foreach (['Installing MySQL', 'Installing PHP 8.4', 'Installing Redis'] as $label) {
        for ($i = 0; $i < 3; $i++) {
            recordStepRun($org, $server, $label, ($i + 1) * 30); // 30, 60, 90 → avg 60
        }
    }

    $hashes = [
        ProvisionStepSnapshots::keyForLabel('Installing MySQL'),
        ProvisionStepSnapshots::keyForLabel('Installing PHP 8.4'),
        ProvisionStepSnapshots::keyForLabel('Installing Redis'),
        ProvisionStepSnapshots::keyForLabel('Never recorded'), // returns no row
    ];

    $out = app(ProvisionStepEtaService::class)->averagesForLabels($hashes, $org);

    expect($out)->toHaveCount(3);
    foreach (['Installing MySQL', 'Installing PHP 8.4', 'Installing Redis'] as $label) {
        $hash = ProvisionStepSnapshots::keyForLabel($label);
        expect($out)->toHaveKey($hash);
        expect($out[$hash]['seconds'])->toBe(60);
        expect($out[$hash]['samples'])->toBe(3);
    }
    $this->assertArrayNotHasKey(
        ProvisionStepSnapshots::keyForLabel('Never recorded'),
        $out,
    );
});
test('organization scope isolates averages', function () {
    [$orgA, $serverA] = freshOrgWithServer();
    [$orgB, $serverB] = freshOrgWithServer();

    // Org A averages 60s.
    for ($i = 0; $i < 3; $i++) {
        recordStepRun($orgA, $serverA, 'Installing MySQL', 60);
    }

    // Org B averages 200s.
    for ($i = 0; $i < 3; $i++) {
        recordStepRun($orgB, $serverB, 'Installing MySQL', 200);
    }

    $hash = ProvisionStepSnapshots::keyForLabel('Installing MySQL');
    $service = app(ProvisionStepEtaService::class);

    expect($service->averageForLabel($hash, $orgA)['seconds'])->toBe(60);
    expect($service->averageForLabel($hash, $orgB)['seconds'])->toBe(200);
});
test('returns null when organization argument is null', function () {
    $hash = ProvisionStepSnapshots::keyForLabel('Installing MySQL');

    expect(app(ProvisionStepEtaService::class)->averageForLabel($hash, null))->toBeNull();
    expect(app(ProvisionStepEtaService::class)->averagesForLabels([$hash], null))->toBe([]);
});
