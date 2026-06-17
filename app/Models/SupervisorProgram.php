<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $server_id
 * @property ?string $site_id
 * @property string $slug
 * @property string $program_type
 * @property string $command
 * @property ?string $directory
 * @property ?string $user
 * @property int $numprocs
 * @property bool $is_active
 * @property ?array<string, mixed> $env_vars
 * @property ?string $autorestart
 * @property ?string $priority
 * @property bool $redirect_stderr
 * @property ?string $startsecs
 * @property ?string $stderr_logfile
 * @property ?string $stdout_logfile
 * @property ?string $stopwaitsecs
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read Server $server
 * @property-read ?Site $site
 */
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'redirect_stderr' => 'boolean',
            'env_vars' => 'encrypted:array',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Site, $this> */
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
        $site = $this->site;
        if ($site !== null) {
            // Atomic-deploy sites run their command from the active release
            // symlink (…/current), exactly like the systemd worker unit — so a
            // supervisor worker program lands on the same code after a deploy.
            $sitePath = $site->isAtomicDeploys()
                ? $site->effectiveEnvDirectory()
                : $site->effectiveRepositoryPath();
            if (trim($sitePath) !== '') {
                return $sitePath;
            }
        }

        return (string) $this->directory;
    }
}
