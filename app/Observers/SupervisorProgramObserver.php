<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\SupervisorProgram;
use App\Services\Servers\SupervisorDaemonAudit;

class SupervisorProgramObserver
{
    public function created(SupervisorProgram $supervisorProgram): void
    {
        SupervisorDaemonAudit::log(
            $supervisorProgram->server,
            $supervisorProgram,
            'program_created',
            ['slug' => $supervisorProgram->slug]
        );
    }

    public function updated(SupervisorProgram $supervisorProgram): void
    {
        if ($supervisorProgram->wasChanged()) {
            SupervisorDaemonAudit::log(
                $supervisorProgram->server,
                $supervisorProgram,
                'program_updated',
                [
                    'slug' => $supervisorProgram->slug,
                    'changed' => array_keys($supervisorProgram->getChanges()),
                ]
            );
        }
    }

    public function deleting(SupervisorProgram $supervisorProgram): void
    {
        SupervisorDaemonAudit::log(
            $supervisorProgram->server,
            $supervisorProgram,
            'program_deleted',
            ['slug' => $supervisorProgram->slug]
        );
    }
}
