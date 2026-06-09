<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for the cloud_database_site table.
 *
 * Carries a ULID primary key, so it needs HasUlids — a plain Eloquent
 * pivot would not populate the `id` column on insert.
 */
class CloudDatabaseSite extends Pivot
{
    use HasUlids;

    protected $table = 'cloud_database_site';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['env_prefix'];
}
