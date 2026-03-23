<?php

namespace App\Models;

use App\Enums\ServerProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    use HasFactory;

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

    protected $fillable = [
        'user_id',
        'organization_id',
        'team_id',
        'provider_credential_id',
        'name',
        'provider',
        'provider_id',
        'ip_address',
        'ssh_port',
        'ssh_user',
        'ssh_private_key',
        'status',
        'region',
        'size',
        'setup_script_key',
        'setup_status',
        'meta',
        'deploy_command',
        'last_health_check_at',
        'health_status',
    ];

    protected function casts(): array
    {
        return [
            'provider' => ServerProvider::class,
            'ssh_private_key' => 'encrypted',
            'meta' => 'array',
            'last_health_check_at' => 'datetime',
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

    public function authorizedKeys(): HasMany
    {
        return $this->hasMany(ServerAuthorizedKey::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(ServerRecipe::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function getSshConnectionString(): string
    {
        return sprintf(
            '%s@%s',
            $this->ssh_user,
            $this->ip_address ?? '0.0.0.0'
        );
    }
}
