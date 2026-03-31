<?php

namespace App\Actions\Servers;

use App\Models\Server;
use App\Models\ServerProvisionRun;
use App\Modules\TaskRunner\Models\Task;

class CreateServerProvisionRun
{
    public function handle(Server $server, Task $task): ServerProvisionRun
    {
        $attempt = (int) $server->provisionRuns()->max('attempt') + 1;

        return ServerProvisionRun::query()->create([
            'server_id' => $server->id,
            'task_id' => $task->id,
            'attempt' => max(1, $attempt),
            'status' => 'running',
            'rollback_status' => 'not_needed',
            'summary' => 'Provisioning has started.',
            'started_at' => now(),
        ]);
    }
}
