<?php

namespace App\Support\Servers;

use App\Models\SupervisorProgram;

/**
 * Supervisor {@see SupervisorProgram} types treated as queue / worker processes.
 */
final class SupervisorQueueProgramTypes
{
    /** @var list<string> */
    public const TYPES = [
        'queue',
        'horizon',
        'sidekiq',
        'solid-queue',
        'celery',
        'bullmq',
        'reverb',
        'octane',
    ];

    public static function includes(string $programType): bool
    {
        return in_array($programType, self::TYPES, true);
    }
}
