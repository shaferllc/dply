<?php

declare(strict_types=1);

namespace Tests\Feature\Console\SweepExpiredMaintenanceWindowsCommandTest;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Servers\ServerMaintenanceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function maintenanceServerWithWindow(?string $until): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'suspended_at' => now(),
        'suspended_reason' => ServerMaintenanceWindow::REASON,
        'meta' => ['suspended_message' => 'Back soon'],
    ]);

    $server->update([
        'meta' => array_merge($server->meta ?? [], [
            'maintenance' => [
                'active' => true,
                'started_at' => now()->subHours(2)->toIso8601String(),
                'until' => $until,
                'suspended_site_ids' => [(string) $site->id],
            ],
        ]),
    ]);

    return [$server->fresh(), $site];
}

test('sweep clears an expired maintenance window and resumes its site', function (): void {
    Queue::fake();

    [$server, $site] = maintenanceServerWithWindow(now()->subHour()->toIso8601String());

    $this->artisan('dply:maintenance:sweep-expired')->assertOk();

    expect(app(ServerMaintenanceWindow::class)->isActive($server->fresh()))->toBeFalse()
        ->and($site->fresh()->isSuspended())->toBeFalse();

    Queue::assertPushed(ApplySiteWebserverConfigJob::class, 1);
});

test('sweep leaves a future-dated maintenance window untouched', function (): void {
    Queue::fake();

    [$server, $site] = maintenanceServerWithWindow(now()->addHour()->toIso8601String());

    $this->artisan('dply:maintenance:sweep-expired')->assertOk();

    expect(app(ServerMaintenanceWindow::class)->isActive($server->fresh()))->toBeTrue()
        ->and($site->fresh()->isSuspended())->toBeTrue();

    Queue::assertNotPushed(ApplySiteWebserverConfigJob::class);
});

test('sweep leaves a clear-only window (no until) untouched', function (): void {
    Queue::fake();

    [$server] = maintenanceServerWithWindow(null);

    $this->artisan('dply:maintenance:sweep-expired')->assertOk();

    expect(app(ServerMaintenanceWindow::class)->isActive($server->fresh()))->toBeTrue();
});
