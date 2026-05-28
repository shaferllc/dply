<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerMaintenanceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('enable maintenance suspends eligible vm sites with shared message', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    $eligible = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    $already = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'suspended_at' => now()->subHour(),
        'suspended_reason' => 'manual',
    ]);

    $maintenance = app(ServerMaintenanceWindow::class);
    $result = $maintenance->enable(
        $server,
        Carbon::now()->addHour(),
        'Patch Tuesday',
        'Back soon',
        $user,
    );

    expect($result['suspended'])->toBe(1)
        ->and($result['already_suspended'])->toBe(1)
        ->and($maintenance->isActive($server->fresh()))->toBeTrue();

    $eligible->refresh();
    $already->refresh();

    expect($eligible->isSuspended())->toBeTrue()
        ->and($eligible->suspended_reason)->toBe(ServerMaintenanceWindow::REASON)
        ->and($eligible->meta['suspended_message'] ?? null)->toBe('Back soon')
        ->and($already->suspended_reason)->toBe('manual');

    Queue::assertPushed(ApplySiteWebserverConfigJob::class, 1);
});

test('disable maintenance resumes only server maintenance sites', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'maintenance' => [
                'active' => true,
                'started_at' => now()->toIso8601String(),
                'suspended_site_ids' => [],
            ],
        ],
    ]);

    $fromMaintenance = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'suspended_at' => now(),
        'suspended_reason' => ServerMaintenanceWindow::REASON,
        'meta' => ['suspended_message' => 'Back soon'],
    ]);

    $manual = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'suspended_at' => now(),
        'suspended_reason' => 'manual',
    ]);

    $server->update([
        'meta' => array_merge($server->meta ?? [], [
            'maintenance' => [
                'active' => true,
                'started_at' => now()->toIso8601String(),
                'suspended_site_ids' => [(string) $fromMaintenance->id],
            ],
        ]),
    ]);

    $result = app(ServerMaintenanceWindow::class)->disable($server->fresh(), $user);

    expect($result['resumed'])->toBe(1)
        ->and($result['left_suspended'])->toBe(0);

    $fromMaintenance->refresh();
    $manual->refresh();

    expect($fromMaintenance->isSuspended())->toBeFalse()
        ->and($manual->isSuspended())->toBeTrue();

    Queue::assertPushed(ApplySiteWebserverConfigJob::class, 1);
});

test('refresh expired clears maintenance when until is past', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'maintenance' => [
                'active' => true,
                'started_at' => now()->subHours(2)->toIso8601String(),
                'until' => now()->subHour()->toIso8601String(),
                'suspended_site_ids' => [],
            ],
        ],
    ]);

    $cleared = app(ServerMaintenanceWindow::class)->refreshExpired($server, $user);

    expect($cleared)->toBeTrue()
        ->and(app(ServerMaintenanceWindow::class)->isActive($server->fresh()))->toBeFalse();
});
