<?php

declare(strict_types=1);

namespace App\Services\Servers;

use Illuminate\Support\Number;

/**
 * Thrown when a built quick-download artifact exceeds the size cap (caught before
 * anything is uploaded). The operator should fall back to the scheduled
 * backup -> destination flow, which streams without the cap.
 */
final class QuickDownloadTooLargeException extends \RuntimeException
{
    public function __construct(
        public readonly int $bytes,
        public readonly int $cap,
    ) {
        parent::__construct(__(':size is over the :cap quick-download limit — use a scheduled backup to a destination instead.', [
            'size' => Number::fileSize($bytes),
            'cap' => Number::fileSize($cap),
        ]));
    }
}
