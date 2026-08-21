<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console\Concerns;

use App\Models\CloudDatabase;

/**
 * Look a managed database up by id or name, the way every `dply:cloud:db:*`
 * command's first argument works. Operators know their databases by name; the
 * id is what scripts pass.
 */
trait ResolvesManagedDatabase
{
    protected function resolveManagedDatabase(string $needle): ?CloudDatabase
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return CloudDatabase::query()
            ->where('id', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
