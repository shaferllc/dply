<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable log drain credential set scoped to an organization, so the team
 * can attach the same Papertrail/Logtail/syslog drain to multiple sites without
 * re-entering secrets each time.
 *
 * The provider-specific fields (host+port for Papertrail/syslog, source_token
 * for Logtail) live in the encrypted {@see $credentials} JSON column so one
 * model handles every provider shape. The dply_realtime provider stores no
 * credentials (dply provides the endpoint via config).
 */
class LogDrainCredential extends Model
{
    use HasUlids;

    protected $table = 'log_drain_credentials';

    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'provider',
        'name',
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
