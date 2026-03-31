<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected function casts(): array
    {
        return [
            'env_vars' => 'encrypted:array',
            'redirect_stderr' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
