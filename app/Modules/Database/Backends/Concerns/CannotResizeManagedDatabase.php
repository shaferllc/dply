<?php

declare(strict_types=1);

namespace App\Modules\Database\Backends\Concerns;

use App\Models\CloudDatabase;
use RuntimeException;

trait CannotResizeManagedDatabase
{
    public function resize(CloudDatabase $database, string $size): void
    {
        throw new RuntimeException(__('This database backend cannot resize a cluster in place.'));
    }
}
