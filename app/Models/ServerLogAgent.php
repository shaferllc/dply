<?php

declare(strict_types=1);

namespace App\Models;

use App\Jobs\InstallLogAgentJob;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per server enrolled in the dply Logs add-on — the per-server edge
 * Vector agent that ships host + service logs to dply. Mirrors the lifecycle
 * shape of {@see ServerCacheService} (status + install_output + version), but
 * there is at most ONE log agent per server (the add-on is a per-server
 * resource), enforced by the unique index on server_id.
 *
 * Enabling the add-on dispatches {@see InstallLogAgentJob}; the workspace renders
 * `install_output` live while status === installing. See docs/SERVER_LOGS_ADDON.md.
 */
class ServerLogAgent extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_INSTALLING = 'installing';

    public const STATUS_RUNNING = 'running';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNINSTALLING = 'uninstalling';

    protected $table = 'server_log_agents';

    protected $fillable = [
        'server_id',
        'status',
        'version',
        'enabled_sources',
        'client_cert_fingerprint',
        'last_seen_at',
        'error_message',
        'install_output',
        'cancel_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled_sources' => 'array',
            'last_seen_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * All source keys defined in config, with their default on/off state.
     *
     * @return array<string, bool>
     */
    public static function configuredSourceDefaults(): array
    {
        $sources = (array) config('server_logs.sources', []);
        $defaults = [];
        foreach ($sources as $key => $meta) {
            $defaults[(string) $key] = (bool) ($meta['default'] ?? false);
        }

        return $defaults;
    }

    /**
     * Resolved on/off state per source for THIS agent: the configured defaults
     * overlaid with the customer's saved toggles. A null `enabled_sources`
     * column means "never customised" → pure config defaults. Unknown keys in
     * the saved blob are ignored so removing a source from config can't leave a
     * dangling toggle.
     *
     * @return array<string, bool>
     */
    public function resolvedSources(): array
    {
        $resolved = self::configuredSourceDefaults();

        $saved = is_array($this->enabled_sources) ? $this->enabled_sources : null;
        if ($saved !== null) {
            foreach ($resolved as $key => $_default) {
                if (array_key_exists($key, $saved)) {
                    $resolved[$key] = (bool) $saved[$key];
                }
            }
        }

        return $resolved;
    }

    /**
     * Source keys that are currently ON for this agent (what vector.toml renders).
     *
     * @return list<string>
     */
    public function activeSourceKeys(): array
    {
        return array_values(array_keys(array_filter($this->resolvedSources())));
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [self::STATUS_INSTALLING, self::STATUS_UNINSTALLING], true);
    }
}
