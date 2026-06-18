<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $autorestart
 * @property string $command
 * @property ?string $description
 * @property string $directory
 * @property array<string, mixed> $env_vars
 * @property string $name
 * @property int $numprocs
 * @property ?string $organization_id
 * @property string $priority
 * @property string $program_type
 * @property bool $redirect_stderr
 * @property string $slug
 * @property string $startsecs
 * @property string $stderr_logfile
 * @property string $stdout_logfile
 * @property string $stopwaitsecs
 * @property string $user
 * @property-read ?Organization $organization
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class OrganizationSupervisorProgramTemplate extends Model
{
    use HasUlids;

    protected $table = 'organization_supervisor_program_templates';

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'program_type',
        'command',
        'directory',
        'user',
        'numprocs',
        'env_vars',
        'stdout_logfile',
        'stderr_logfile',
        'priority',
        'startsecs',
        'stopwaitsecs',
        'autorestart',
        'redirect_stderr',
        'description',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'env_vars' => 'encrypted:array',
            'redirect_stderr' => 'boolean',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
