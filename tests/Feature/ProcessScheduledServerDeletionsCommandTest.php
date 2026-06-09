<?php

declare(strict_types=1);

namespace Tests\Feature\ProcessScheduledServerDeletionsCommandTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('deletes servers whose scheduled deletion is due', function () {
    Notification::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $due = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'scheduled_deletion_at' => now()->subMinutes(5),
        'meta' => ['scheduled_deletion_reason' => 'cost cleanup'],
    ]);
    $future = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'scheduled_deletion_at' => now()->addDay(),
    ]);
    $unscheduled = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $exit = Artisan::call('dply:process-scheduled-server-deletions');

    expect($exit)->toBe(0);
    expect(Server::query()->find($due->id))->toBeNull();
    expect(Server::query()->find($future->id))->not->toBeNull();
    expect(Server::query()->find($unscheduled->id))->not->toBeNull();
});
test('no op when nothing due', function () {
    $user = User::factory()->create();
    Server::factory()->ready()->create([
        'user_id' => $user->id,
        'scheduled_deletion_at' => now()->addDay(),
    ]);

    $exit = Artisan::call('dply:process-scheduled-server-deletions');

    expect($exit)->toBe(0);
    expect(trim(Artisan::output()))->toBe('');
});
test('records audit log for deletion', function () {
    Notification::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'scheduled_deletion_at' => now()->subMinute(),
        'meta' => ['scheduled_deletion_reason' => 'org wind-down'],
    ]);
    $serverId = $server->id;

    Artisan::call('dply:process-scheduled-server-deletions');

    $audit = \DB::table('audit_logs')
        ->where('subject_type', Server::class)
        ->where('subject_id', $serverId)
        ->where('action', 'server.deleted')
        ->first();
    expect($audit)->not->toBeNull();
    $newValues = json_decode($audit->new_values, true);
    expect($newValues['reason'] ?? null)->toBe('org wind-down');
    expect($newValues['scheduled_completion'] ?? false)->toBeTrue();
});
