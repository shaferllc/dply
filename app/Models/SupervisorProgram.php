<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupervisorProgram extends Model
{
    use HasUlids;

    protected $fillable = [
        'server_id',
        'site_id',
        'slug',
        'program_type',
        'command',
        'directory',
        'user',
        'numprocs',
        'is_active',
        'env_vars',
        'stdout_logfile',
        'priority',
        'startsecs',
        'stopwaitsecs',
        'autorestart',
        'redirect_stderr',
        'stderr_logfile',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'redirect_stderr' => 'boolean',
            'env_vars' => 'encrypted:array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
