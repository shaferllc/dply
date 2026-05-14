<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportServerMigration extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SERVER_PROVISIONING = 'server_provisioning';

    public const STATUS_STAGING = 'staging';

    public const STATUS_READY_FOR_CUTOVER = 'ready_for_cutover';

    public const STATUS_CUTOVER_IN_PROGRESS = 'cutover_in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_ABORTED = 'aborted';

    public const STATUS_CUTOVER_FAILED = 'cutover_failed';

    protected $fillable = [
        'organization_id',
        'user_id',
        'provider_credential_id',
        'source',
        'source_server_id',
        'target_server_id',
        'status',
        'ssh_key_fingerprint',
        'ssh_key_public',
        'ssh_key_private_encrypted',
        'ssh_key_source_id',
        'ssh_key_pushed_at',
        'ssh_key_revoked_at',
        'started_at',
        'completed_at',
        'failure_summary',
    ];

    protected function casts(): array
    {
        return [
            'source_server_id' => 'integer',
            'ssh_key_source_id' => 'integer',
            'ssh_key_pushed_at' => 'datetime',
            'ssh_key_revoked_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class);
    }

    public function targetServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'target_server_id');
    }

    public function siteMigrations(): HasMany
    {
        return $this->hasMany(ImportSiteMigration::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ImportMigrationStep::class)->orderBy('sequence');
    }
}
