<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Logs\Jobs\InstallLogAggregatorJob;
use App\Support\Servers\VectorLogAgentInstallScripts;
use App\Support\Servers\VectorLogAggregatorInstallScripts;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      The dply Logs Vector aggregator — the ingest tier that authenticates edges over
 *                      mTLS, stamps tenant identity, and bulk-inserts into ClickHouse. At most one per
 *                      server (the box designated as the aggregator), enforced by the unique index on
 *                      server_id. Stood up by {@see InstallLogAggregatorJob}.
 *                      Holds the edge mTLS material (CA + client cert/key) the install generated ON the
 *                      box and handed back, so the edge installer ({@see VectorLogAgentInstallScripts})
 *                      can configure shipping without any manual env. The cert material is encrypted at
 *                      rest. See docs/SERVER_LOGS_ADDON.md.
 * @property string $edge_ca_cert_b64
 * @property string $edge_client_cert_b64
 * @property string $edge_client_key_b64
 * @property string $endpoint
 * @property ?string $private_endpoint
 * @property ?string $error_message
 * @property string $install_output
 * @property ?Carbon $last_seen_at
 * @property int $listen_port
 * @property ?string $server_id
 * @property string $status
 * @property string $version
 * @property ?int $config_version
 * @property-read ?Server $server
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class ServerLogAggregator extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_INSTALLING = 'installing';

    public const STATUS_RUNNING = 'running';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNINSTALLING = 'uninstalling';

    protected $table = 'server_log_aggregators';

    protected $fillable = [
        'server_id',
        'status',
        'version',
        'config_version',
        'listen_port',
        'endpoint',
        'private_endpoint',
        'edge_ca_cert_b64',
        'edge_client_cert_b64',
        'edge_client_key_b64',
        'install_output',
        'error_message',
        'last_seen_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'listen_port' => 'integer',
            'config_version' => 'integer',
            'last_seen_at' => 'datetime',
            'edge_ca_cert_b64' => 'encrypted',
            'edge_client_cert_b64' => 'encrypted',
            'edge_client_key_b64' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [self::STATUS_INSTALLING, self::STATUS_UNINSTALLING], true);
    }

    /**
     * True once the install captured the edge mTLS material — i.e. edges can be
     * pointed here with a real client cert.
     */
    public function hasEdgeMaterial(): bool
    {
        return filled($this->edge_ca_cert_b64)
            && filled($this->edge_client_cert_b64)
            && filled($this->edge_client_key_b64);
    }

    /**
     * The config version this build of dply renders (the target a re-sync installs).
     */
    public static function currentConfigVersion(): int
    {
        return VectorLogAggregatorInstallScripts::CONFIG_VERSION;
    }

    /**
     * The aggregator config the box is actually running, as last recorded by an
     * install. Null = predates versioning / never re-synced since → treat as stale.
     */
    public function installedConfigVersion(): ?int
    {
        return $this->config_version;
    }

    /**
     * True when the box is running an older config than this build renders — the
     * operator should re-sync to pick it up. Only meaningful for a running box.
     */
    public function isConfigStale(): bool
    {
        if (! $this->isRunning()) {
            return false;
        }

        return ($this->config_version ?? 0) < self::currentConfigVersion();
    }
}
