<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 */

class SupervisorProgramAuditLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'supervisor_program_audit_logs';

    protected $fillable = [
        'organization_id',
        'server_id',
        'supervisor_program_id',
        'user_id',
        'action',
        'properties',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<SupervisorProgram, $this> */
    public function supervisorProgram(): BelongsTo {
        return $this->belongsTo(SupervisorProgram::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }
}
