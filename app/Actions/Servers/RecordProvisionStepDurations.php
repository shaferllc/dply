<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Models\Server;
use App\Models\ServerProvisionRun;
use App\Models\ServerProvisionStepRun;
use App\Modules\TaskRunner\Models\Task;
use App\Support\Servers\ProvisionPipelineLog;
use App\Support\Servers\ProvisionStepDurations;

/**
 * Bulk-insert one row per `[dply-step-end]` marker into
 * server_provision_step_runs. Idempotent on (task_id, label_hash) — the
 * unique index lets the observer fire on every output update without
 * worrying about duplicates.
 */
class RecordProvisionStepDurations
{
    public function handle(Server $server, Task $task, ?ServerProvisionRun $run = null): int
    {
        $output = is_string($task->output) ? $task->output : '';
        $rows = ProvisionStepDurations::parse($output);
        if ($rows === []) {
            return 0;
        }

        // Only consider task_id-deduped rows. The unique index protects
        // us at the DB level too, but pre-filtering avoids the round trip.
        $existingHashes = ServerProvisionStepRun::query()
            ->where('task_id', $task->id)
            ->pluck('label_hash')
            ->all();
        $existing = array_flip($existingHashes);

        $now = now();
        $payload = [];
        foreach ($rows as $row) {
            if (isset($existing[$row['label_hash']])) {
                continue;
            }
            $existing[$row['label_hash']] = true;

            // started_at is reconstructed from the duration so the row
            // carries usable timing even though the bash only emits a
            // single end-of-step marker. completed_at is "now" because
            // the observer runs on every output write — by the time we
            // see the marker, the step is on disk.
            $completedAt = $now;
            $startedAt = $now->copy()->subSeconds(max(0, $row['duration_seconds']));

            $payload[] = [
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'server_id' => $server->id,
                'organization_id' => $server->organization_id,
                'server_provision_run_id' => $run?->id,
                'task_id' => $task->id,
                'label_hash' => $row['label_hash'],
                'label' => mb_substr($row['label'], 0, 255),
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'duration_seconds' => max(0, $row['duration_seconds']),
                'resumed' => $row['resumed'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($payload === []) {
            return 0;
        }

        ServerProvisionStepRun::query()->insert($payload);

        ProvisionPipelineLog::info('server.provision.step_durations.recorded', $server, [
            'phase' => 'observer',
            'task_id' => $task->id,
            'rows_inserted' => count($payload),
            'resumed_rows' => count(array_filter($payload, static fn (array $r): bool => (bool) $r['resumed'])),
        ]);

        return count($payload);
    }
}
