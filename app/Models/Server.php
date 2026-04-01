<?php

namespace App\Models;

use App\Enums\ServerProvider;
use App\Modules\TaskRunner\Connection as TaskRunnerConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;

class Server extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const HEALTH_REACHABLE = 'reachable';

    public const HEALTH_UNREACHABLE = 'unreachable';

    public const SETUP_STATUS_PENDING = 'pending';

    public const SETUP_STATUS_RUNNING = 'running';

    public const SETUP_STATUS_DONE = 'done';

    public const SETUP_STATUS_FAILED = 'failed';

    public const SUPERVISOR_PACKAGE_INSTALLED = 'installed';

    public const SUPERVISOR_PACKAGE_MISSING = 'missing';

    protected $fillable = [
        'user_id',
        'organization_id',
        'workspace_id',
        'team_id',
        'provider_credential_id',
        'name',
        'provider',
        'provider_id',
        'ip_address',
        'ssh_port',
        'ssh_user',
        'ssh_private_key',
        'ssh_operational_private_key',
        'ssh_recovery_private_key',
        'status',
        'region',
        'size',
        'setup_script_key',
        'setup_status',
        'meta',
        'supervisor_package_status',
        'deploy_command',
        'last_health_check_at',
        'health_status',
        'scheduled_deletion_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => ServerProvider::class,
            'ssh_private_key' => 'encrypted',
            'ssh_operational_private_key' => 'encrypted',
            'ssh_recovery_private_key' => 'encrypted',
            'meta' => 'array',
            'last_health_check_at' => 'datetime',
            'scheduled_deletion_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function serverDatabases(): HasMany
    {
        return $this->hasMany(ServerDatabase::class);
    }

    public function databaseAdminCredential(): HasOne
    {
        return $this->hasOne(ServerDatabaseAdminCredential::class);
    }

    public function databaseAuditEvents(): HasMany
    {
        return $this->hasMany(ServerDatabaseAuditEvent::class)->orderByDesc('created_at');
    }

    public function cronJobs(): HasMany
    {
        return $this->hasMany(ServerCronJob::class);
    }

    public function supervisorPrograms(): HasMany
    {
        return $this->hasMany(SupervisorProgram::class);
    }

    public function firewallRules(): HasMany
    {
        return $this->hasMany(ServerFirewallRule::class)->orderBy('sort_order');
    }

    public function firewallSnapshots(): HasMany
    {
        return $this->hasMany(ServerFirewallSnapshot::class)->orderByDesc('created_at');
    }

    public function firewallAuditEvents(): HasMany
    {
        return $this->hasMany(ServerFirewallAuditEvent::class)->orderByDesc('created_at');
    }

    public function firewallApplyLogs(): HasMany
    {
        return $this->hasMany(ServerFirewallApplyLog::class)->orderByDesc('created_at');
    }

    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(ServerMetricSnapshot::class)->orderByDesc('captured_at');
    }

    public function systemdServiceStates(): HasMany
    {
        return $this->hasMany(ServerSystemdServiceState::class)->orderBy('label');
    }

    public function systemdServiceAuditEvents(): HasMany
    {
        return $this->hasMany(ServerSystemdServiceAuditEvent::class)->orderByDesc('occurred_at');
    }

    public function insightSetting(): MorphOne
    {
        return $this->morphOne(InsightSetting::class, 'settingsable');
    }

    public function insightFindings(): HasMany
    {
        return $this->hasMany(InsightFinding::class)->orderByDesc('detected_at');
    }

    public function authorizedKeys(): HasMany
    {
        return $this->hasMany(ServerAuthorizedKey::class);
    }

    public function sshKeyAuditEvents(): HasMany
    {
        return $this->hasMany(ServerSshKeyAuditEvent::class)->orderByDesc('created_at');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(ServerRecipe::class);
    }

    public function provisionRuns(): HasMany
    {
        return $this->hasMany(ServerProvisionRun::class)->orderByDesc('created_at');
    }

    public function notificationSubscriptions(): MorphMany
    {
        return $this->morphMany(NotificationSubscription::class, 'subscribable');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * OpenSSH one-line public key derived from the stored provisioned private key.
     */
    public function openSshPublicKeyFromPrivate(): ?string
    {
        return $this->openSshPublicKeyFromKey($this->operationalSshPrivateKey());
    }

    public function openSshPublicKeyFromOperationalPrivate(): ?string
    {
        return $this->openSshPublicKeyFromKey($this->operationalSshPrivateKey());
    }

    public function openSshPublicKeyFromRecoveryPrivate(): ?string
    {
        return $this->openSshPublicKeyFromKey($this->recoverySshPrivateKey());
    }

    public function operationalSshPrivateKey(): ?string
    {
        $key = $this->ssh_operational_private_key;

        if (is_string($key) && trim($key) !== '') {
            return $key;
        }

        $legacy = $this->ssh_private_key;

        return is_string($legacy) && trim($legacy) !== '' ? $legacy : null;
    }

    public function recoverySshPrivateKey(): ?string
    {
        $key = $this->ssh_recovery_private_key;

        if (is_string($key) && trim($key) !== '') {
            return $key;
        }

        $legacy = $this->ssh_private_key;

        return is_string($legacy) && trim($legacy) !== '' ? $legacy : null;
    }

    public function hasAnySshPrivateKey(): bool
    {
        return $this->operationalSshPrivateKey() !== null || $this->recoverySshPrivateKey() !== null;
    }

    public function hasPersonalUserSshKey(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->authorizedKeys()
            ->where('managed_key_type', UserSshKey::class)
            ->whereIn('managed_key_id', $user->sshKeys()->select('id'))
            ->exists();
    }

    public function hasDedicatedOperationalSshPrivateKey(): bool
    {
        $key = $this->ssh_operational_private_key;

        return is_string($key) && trim($key) !== '';
    }

    protected function openSshPublicKeyFromKey(?string $priv): ?string
    {
        if (! is_string($priv) || trim($priv) === '') {
            return null;
        }

        try {
            $key = PublicKeyLoader::load($priv);
            if (! $key instanceof PrivateKey) {
                return null;
            }

            return trim($key->getPublicKey()->toString('OpenSSH'));
        } catch (\Throwable) {
            return null;
        }
    }

    public function getSshConnectionString(): string
    {
        return sprintf(
            '%s@%s',
            $this->ssh_user,
            $this->ip_address ?? '0.0.0.0'
        );
    }

    /**
     * SSH connection for TaskRunner remote execution as the server's deploy user.
     */
    public function connectionAsUser(): TaskRunnerConnection
    {
        return $this->connectionAsOperationalUser();
    }

    public function connectionAsOperationalUser(): TaskRunnerConnection
    {
        $user = trim((string) $this->ssh_user);
        if ($user === '') {
            throw new \RuntimeException('Server has no SSH user configured.');
        }

        return $this->taskRunnerConnectionAs($user, $this->operationalSshPrivateKey());
    }

    /**
     * SSH connection for TaskRunner remote execution as root.
     */
    public function connectionAsRoot(): TaskRunnerConnection
    {
        return $this->connectionAsRecoveryRoot();
    }

    public function connectionAsRecoveryRoot(): TaskRunnerConnection
    {
        return $this->taskRunnerConnectionAs('root', $this->recoverySshPrivateKey());
    }

    protected function taskRunnerConnectionAs(string $username, ?string $key): TaskRunnerConnection
    {
        if ($key === null || trim((string) $key) === '') {
            throw new \RuntimeException('Server has no SSH private key configured.');
        }

        $host = trim((string) $this->ip_address);
        if ($host === '') {
            throw new \RuntimeException('Server has no IP address.');
        }

        return TaskRunnerConnection::fromArray([
            'host' => $host,
            'port' => (int) ($this->ssh_port ?: 22),
            'username' => $username,
            'private_key' => $key,
        ]);
    }
}
