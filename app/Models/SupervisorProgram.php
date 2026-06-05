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

    /**
     * Working directory used to build the supervisor conf and shown in the UI.
     *
     * For a site-scoped program the site's CURRENT repository path is the
     * source of truth: this self-heals programs imported from another platform
     * that carry a foreign path (e.g. Forge's "/home/.../apps/<x>/current",
     * which doesn't exist on a dply box where sites live at /home/dply/<host>)
     * and survives `dply:site:relocate`. Falls back to the stored directory for
     * server-level programs or when no site path is resolvable.
     */
    public function effectiveDirectory(): string
    {
        $sitePath = $this->site?->effectiveRepositoryPath();
        if (is_string($sitePath) && trim($sitePath) !== '') {
            return $sitePath;
        }

        return (string) $this->directory;
    }
}
