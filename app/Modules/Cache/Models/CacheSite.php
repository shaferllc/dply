<?php

declare(strict_types=1);

namespace App\Modules\Cache\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * The cache↔site attachment.
 *
 * A real pivot model rather than a bare table, mirroring `CloudDatabaseSite`:
 * attach and detach both need to reason about the row (which keys were
 * injected, what prefix was chosen), and a plain `attach()` leaves nowhere to
 * put that.
 *
 * @property string $id
 * @property string $cache_id
 * @property ?string $key_prefix
 * @property string $site_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CacheSite extends Pivot
{
    use HasUlids;

    protected $table = 'cache_site';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['cache_id', 'site_id', 'key_prefix'];
}
