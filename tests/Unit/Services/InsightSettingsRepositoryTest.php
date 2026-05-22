<?php


namespace Tests\Unit\Services\InsightSettingsRepositoryTest;
use App\Models\Organization;
use App\Services\Insights\InsightSettingsRepository;
use PHPUnit\Framework\Attributes\Test;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('default enabled map turns off pro insights without subscription', function () {
    // Heartbeat default_enabled is env-driven (INSIGHTS_HEARTBEAT_DEFAULT_ENABLED).
    // Pin it to false here so the test doesn't depend on the developer's local env.
    config(['insights.insights.insights_pipeline_heartbeat.default_enabled' => false]);

    $org = Organization::factory()->create();
    $repo = new InsightSettingsRepository;

    $map = $repo->defaultEnabledMap($org);

    expect($map)->toHaveKey('npm_vulnerabilities');
    expect($map['npm_vulnerabilities'])->toBeFalse();
    expect($map)->toHaveKey('cpu_ram_usage');
    expect($map['cpu_ram_usage'])->toBeTrue();
    expect($map)->toHaveKey('insights_pipeline_heartbeat');
    expect($map['insights_pipeline_heartbeat'])->toBeFalse();
});