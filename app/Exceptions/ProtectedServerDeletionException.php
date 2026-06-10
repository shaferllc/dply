<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Server;
use RuntimeException;

/**
 * Thrown when something tries to delete a dply-protected server — the control
 * plane's own dogfood infrastructure (tagged `dply` or self-adopted via
 * {@see App\Console\Commands\SelfAdoptCommand}). These boxes must never be
 * destroyed from the panel: not the cloud host, not the database row.
 *
 * {@see App\Models\Server::isDeletionProtected()} is the source of truth and is
 * also enforced earlier in {@see App\Policies\ServerPolicy::delete()} so the UI
 * never offers the action. This exception is the hard backstop for any caller
 * that reaches {@see App\Actions\Servers\DeleteServerAction} directly.
 */
class ProtectedServerDeletionException extends RuntimeException
{
    public static function for(Server $server): self
    {
        return new self("Server [{$server->name}] is protected (tagged dply / self-managed) and cannot be deleted.");
    }
}
