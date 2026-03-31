<?php

namespace App\Jobs;

use RuntimeException;

/**
 * Thrown when a newer manage SSH request replaced this job so the worker should stop the remote command.
 */
class ManageRemoteTaskSupersededException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Manage remote task superseded by a newer request.');
    }
}
