<?php

declare(strict_types=1);

namespace App\Services\Servers;

use Illuminate\Support\Number;

/**
 * Thrown when a staged quick-download artifact exceeds the live-stream cap.
 * The operator should fall back to the scheduled backup -> destination flow.
 */
final class QuickDownloadTooLargeException extends \RuntimeException
{
    public function __construct(
        public readonly int $bytes,
        public readonly int $cap,
    ) {
        parent::__construct(__(':size is over the :cap live-download limit — use a scheduled backup to a destination instead.', [
            'size' => Number::fileSize($bytes),
            'cap' => Number::fileSize($cap),
        ]));
    }
}
