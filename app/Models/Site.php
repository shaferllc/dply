<?php

namespace App\Models;

use App\Enums\SiteType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Site extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_NGINX_ACTIVE = 'nginx_active';

    public const STATUS_ERROR = 'error';

    public const SSL_NONE = 'none';

    public const SSL_PENDING = 'pending';

    public const SSL_ACTIVE = 'active';

    public const SSL_FAILED = 'failed';

    protected $fillable = [
        'server_id',
        'user_id',
        'organization_id',
        'workspace_id',
        'name',
        'slug',
        'type',
        'document_root',
        'repository_path',
        'php_version',
        'app_port',
        'status',
        'ssl_status',
        'nginx_installed_at',
        'ssl_installed_at',
        'last_deploy_at',
        'git_repository_url',
        'git_branch',
        'git_deploy_key_private',
        'git_deploy_key_public',
        'webhook_secret',
        'webhook_allowed_ips',
        'post_deploy_command',
        'deploy_script_id',
        'deploy_strategy',
        'releases_to_keep',
        'nginx_extra_raw',
        'octane_port',
        'laravel_scheduler',
        'restart_supervisor_programs_after_deploy',
        'deployment_environment',
        'php_fpm_user',
        'env_file_content',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => SiteType::class,
            'git_deploy_key_private' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'webhook_allowed_ips' => 'array',
            'env_file_content' => 'encrypted',
            'meta' => 'array',
            'laravel_scheduler' => 'boolean',
            'restart_supervisor_programs_after_deploy' => 'boolean',
            'nginx_installed_at' => 'datetime',
            'ssl_installed_at' => 'datetime',
            'last_deploy_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Site $site): void {
            if (empty($site->slug)) {
                $site->slug = Str::slug($site->name) ?: 'site';
            }
            if ($site->server_id && ! $site->organization_id) {
                $server = Server::query()->find($site->server_id);
                if ($server) {
                    $site->organization_id = $server->organization_id;
                }
            }
            if ($site->workspace_id === null && $site->server_id) {
                $server = Server::query()->find($site->server_id);
                if ($server?->workspace_id) {
                    $site->workspace_id = $server->workspace_id;
                }
            }
            if ($site->project_id === null) {
                $project = Project::query()->create([
                    'organization_id' => $site->organization_id,
                    'user_id' => $site->user_id,
                    'name' => $site->name ?: 'Site',
                    'slug' => 'tmp-'.Str::lower(Str::random(20)),
                    'kind' => Project::KIND_BYO_SITE,
                ]);
                $site->project_id = $project->id;
            }
        });

        static::created(function (Site $site): void {
            $site->project()->update([
                'slug' => $site->slug.'-'.$site->id,
                'name' => $site->name,
            ]);
        });

        static::deleted(function (Site $site): void {
            if ($site->project_id) {
                Project::query()->whereKey($site->project_id)->delete();
            }
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
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

    public function deployScript(): BelongsTo
    {
        return $this->belongsTo(Script::class, 'deploy_script_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(SiteDomain::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(SiteDeployment::class)->orderByDesc('id');
    }

    public function webhookDeliveryLogs(): HasMany
    {
        return $this->hasMany(WebhookDeliveryLog::class)->orderByDesc('id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(SiteRelease::class)->orderByDesc('id');
    }

    public function environmentVariables(): HasMany
    {
        return $this->hasMany(SiteEnvironmentVariable::class)->orderBy('env_key');
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(SiteRedirect::class)->orderBy('sort_order');
    }

    public function deployHooks(): HasMany
    {
        return $this->hasMany(SiteDeployHook::class)->orderBy('sort_order');
    }

    public function deploySteps(): HasMany
    {
        return $this->hasMany(SiteDeployStep::class)->orderBy('sort_order');
    }

    public function primaryDomain(): ?SiteDomain
    {
        return $this->domains()->where('is_primary', true)->first()
            ?? $this->domains()->first();
    }

    public function effectiveRepositoryPath(): string
    {
        $path = $this->repository_path;

        return $path !== null && $path !== '' ? $path : $this->document_root;
    }

    public function isAtomicDeploys(): bool
    {
        return $this->deploy_strategy === 'atomic';
    }

    /**
     * Web root for Nginx (atomic → …/current/public).
     */
    public function effectiveDocumentRootForNginx(): string
    {
        if ($this->isAtomicDeploys()) {
            return rtrim($this->effectiveRepositoryPath(), '/').'/current/public';
        }

        return rtrim($this->document_root, '/');
    }

    /**
     * Directory that receives .env (project root).
     */
    public function effectiveEnvDirectory(): string
    {
        if ($this->isAtomicDeploys()) {
            return rtrim($this->effectiveRepositoryPath(), '/').'/current';
        }

        return rtrim($this->effectiveRepositoryPath(), '/');
    }

    public function nginxConfigBasename(): string
    {
        return 'dply-'.$this->id.'-'.$this->slug;
    }

    public function deployHookUrl(): string
    {
        return route('hooks.site.deploy', ['site' => $this->id]);
    }

    public function notificationSubscriptions(): MorphMany
    {
        return $this->morphMany(NotificationSubscription::class, 'subscribable');
    }

    public function ensureUniqueSlug(): void
    {
        $base = $this->slug;
        $i = 1;
        while ($this->slugTaken()) {
            $this->slug = $base.'-'.$i;
            $i++;
        }
    }

    protected function slugTaken(): bool
    {
        $q = static::query()
            ->where('server_id', $this->server_id)
            ->where('slug', $this->slug);
        if ($this->exists) {
            $q->whereKeyNot($this->getKey());
        }

        return $q->exists();
    }
}
