<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A persisted resource binding for a site: a managed attachment (database,
 * redis, queue, object storage, scheduler, workers, publication) that
 * contributes connection variables to the deploy environment.
 *
 * The connection vars live in {@see $injected_env} (encrypted) and are merged
 * into the deployment environment at deploy time only — they are intentionally
 * kept out of the editable Variables list so the binding stays the source of
 * truth for them.
 */
class SiteBinding extends Model
{
    use HasUlids;

    public const TYPES = [
        'database',
        'scheduler',
        'workers',
        'publication',
        'redis',
        'queue',
        'storage',
        'cache',
        'session',
    ];

    public const STATUS_CONFIGURED = 'configured';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ERROR = 'error';

    protected $table = 'site_bindings';

    protected $fillable = [
        'site_id',
        'type',
        'mode',
        'status',
        'name',
        'target_type',
        'target_id',
        'injected_env',
        'config',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'injected_env' => 'encrypted:array',
            'config' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Connection variables this binding contributes at deploy time.
     *
     * @return array<string, string>
     */
    public function connectionEnv(): array
    {
        $env = $this->injected_env;
        if (! is_array($env)) {
            return [];
        }

        $clean = [];
        foreach ($env as $key => $value) {
            if (is_string($key) && $key !== '') {
                $clean[$key] = (string) $value;
            }
        }

        return $clean;
    }
}
