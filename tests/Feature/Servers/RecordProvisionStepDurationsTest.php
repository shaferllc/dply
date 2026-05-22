<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\RecordProvisionStepDurationsTest;
use App\Actions\Servers\RecordProvisionStepDurations;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerProvisionStepRun;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Support\Servers\ProvisionStepSnapshots;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeProvisionTask(Server $server, string $output): Task
{
    return Task::query()->create([
        'name' => 'Provision stack',
        'action' => 'provision_stack',
        'script' => 'remote',
        'status' => TaskStatus::Finished,
        'server_id' => $server->id,
        'output' => $output,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now(),
    ]);
}
test('inserts one row per step end marker', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $task = makeProvisionTask($server, implode("\n", [
        '[dply-step] Installing system updates',
        '[dply-step-end] Installing system updates'."\t".'45',
        '[dply-step] Installing MySQL',
        '[dply-step-end] Installing MySQL'."\t".'120',
        '',
    ]));

    $count = app(RecordProvisionStepDurations::class)->handle($server, $task);

    expect($count)->toBe(2);
    $rows = ServerProvisionStepRun::query()->where('task_id', $task->id)->get();
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('label')->all())->toEqualCanonicalizing(['Installing system updates', 'Installing MySQL']);
    expect($rows->pluck('duration_seconds')->all())->toEqualCanonicalizing([45, 120]);
    expect($rows->first()->organization_id)->toBe($org->id);
});
test('marks resumed steps with resumed flag', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $task = makeProvisionTask($server, implode("\n", [
        '[dply-step-end] Installing MySQL'."\t".'0'."\t".'resumed',
        '',
    ]));

    app(RecordProvisionStepDurations::class)->handle($server, $task);

    $row = ServerProvisionStepRun::query()->where('task_id', $task->id)->first();
    expect($row)->not->toBeNull();
    expect($row->resumed)->toBeTrue();
    expect($row->duration_seconds)->toBe(0);
    expect($row->label_hash)->toBe(ProvisionStepSnapshots::keyForLabel('Installing MySQL'));
});
test('is idempotent when called twice for same task', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $task = makeProvisionTask($server, "[dply-step-end] Installing MySQL\t60\n");

    $first = app(RecordProvisionStepDurations::class)->handle($server, $task);
    $second = app(RecordProvisionStepDurations::class)->handle($server, $task);

    expect($first)->toBe(1, 'First call inserts the new row.');
    expect($second)->toBe(0, 'Second call must skip the duplicate (task_id, label_hash).');
    expect(ServerProvisionStepRun::query()->where('task_id', $task->id)->count())->toBe(1);
});
