<?php

declare(strict_types=1);

namespace App\Services\RemoteCli;

use RuntimeException;

/**
 * Thrown by {@see RemoteCli::run()} when the calling user lacks the
 * org-level role required for the command's risk classification.
 *
 * Caught by Livewire components and CLI commands to render a friendly
 * "your role can't run destructive commands on this site" message.
 */
class RemoteCliPermissionDeniedException extends RuntimeException
{
    public function __construct(
        public readonly RiskLevel $risk,
        public readonly string $command,
    ) {
        parent::__construct(sprintf(
            'Permission denied: command [%s] is classified as %s and requires admin or owner role.',
            $command,
            $risk->value,
        ));
    }
}
