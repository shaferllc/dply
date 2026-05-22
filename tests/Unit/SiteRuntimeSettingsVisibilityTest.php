<?php


namespace Tests\Unit\SiteRuntimeSettingsVisibilityTest;
use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('should show php octane when site type is php', function () {
    $server = Server::factory()->ready()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'type' => SiteType::Php,
        'meta' => null,
    ]);

    expect($site->shouldShowPhpOctaneRolloutSettings())->toBeTrue();
});

test('should show rails when detection framework is rails', function () {
    $server = Server::factory()->ready()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'type' => SiteType::Node,
        'meta' => [
            'docker_runtime' => [
                'detected' => [
                    'framework' => 'rails',
                    'language' => 'ruby',
                ],
            ],
        ],
    ]);

    expect($site->shouldShowRailsRuntimeSettings())->toBeTrue();
    expect($site->shouldShowPhpOctaneRolloutSettings())->toBeFalse();
});