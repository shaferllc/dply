<?php

namespace App\Models;

use Database\Factories\SiteProcessFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $command
 * @property array<string, mixed> $env_vars
 * @property bool $is_active
 * @property bool $managed_by_manifest
 * @property array<string, mixed>|null $meta
 * @property string $name
 * @property int $scale
 * @property ?string $site_id
 * @property string $type
 * @property string $user
 * @property string $working_directory
 * @property-read ?Site $site
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SiteProcess extends Model
{
    /** @use HasFactory<SiteProcessFactory> */
    use HasFactory, HasUlids;

    public const TYPE_WEB = 'web';

    public const TYPE_WORKER = 'worker';

    public const TYPE_SCHEDULER = 'scheduler';

    public const TYPE_CUSTOM = 'custom';

    protected $fillable = [
        'site_id',
        'type',
        'name',
        'command',
        'scale',
        'env_vars',
        'working_directory',
        'user',
        'is_active',
        'managed_by_manifest',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'env_vars' => 'encrypted:array',
            'meta' => 'array',
            'is_active' => 'boolean',
            'scale' => 'integer',
            'managed_by_manifest' => 'boolean',
        ];
    }

    /**
     * Whether this process should run on a host with the given runtime mode/role.
     * Empty roles = apply everywhere (customer BYO default).
     */
    public function matchesRuntimeRole(string $runtimeMode, string $workerRole = 'primary'): bool
    {
        $roles = is_array($this->meta['roles'] ?? null) ? $this->meta['roles'] : [];
        if ($roles === []) {
            return true;
        }

        $runtimeMode = strtolower(trim($runtimeMode));
        $workerRole = strtolower(trim($workerRole));

        // Unknown / monolith host — install every declared process.
        if ($runtimeMode === '' || $runtimeMode === 'all') {
            return true;
        }

        foreach ($roles as $role) {
            if (! is_string($role)) {
                continue;
            }
            $role = strtolower(trim($role));
            if ($role === $runtimeMode || $role === 'all') {
                return true;
            }
            if ($runtimeMode === 'worker' && $role === 'worker') {
                return true;
            }
            if ($runtimeMode === 'worker' && $role === 'worker:'.$workerRole) {
                return true;
            }
            if ($runtimeMode === 'web' && $role === 'web') {
                return true;
            }
        }

        return false;
    }

    public function isOneshot(): bool
    {
        return (bool) ($this->meta['oneshot'] ?? false);
    }

    public function loopSeconds(): ?int
    {
        $value = $this->meta['loop_seconds'] ?? null;

        return is_numeric($value) ? max(1, (int) $value) : null;
    }

    public function stopwaitsecs(): ?int
    {
        $value = $this->meta['stopwaitsecs'] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
