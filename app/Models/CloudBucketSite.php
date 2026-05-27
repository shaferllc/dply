<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for the cloud_bucket_site table. Mirrors {@see CloudDatabaseSite}.
 */
class CloudBucketSite extends Pivot
{
    use HasUlids;

    protected $table = 'cloud_bucket_site';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['env_prefix'];
}
