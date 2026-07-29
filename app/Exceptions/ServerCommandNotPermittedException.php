<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \App\Services\Servers\ServerCommandRunner} when the
 * acting user's org role isn't allowed to execute shell on the server
 * (Deployers are blocked from arbitrary command execution).
 */
class ServerCommandNotPermittedException extends RuntimeException
{
}
