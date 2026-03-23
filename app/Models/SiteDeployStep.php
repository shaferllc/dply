<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDeployStep extends Model
{
    public const TYPE_COMPOSER_INSTALL = 'composer_install';

    public const TYPE_NPM_CI = 'npm_ci';

    public const TYPE_NPM_INSTALL = 'npm_install';

    public const TYPE_NPM_RUN = 'npm_run';

    public const TYPE_ARTISAN_MIGRATE = 'artisan_migrate';

    public const TYPE_ARTISAN_CONFIG_CACHE = 'artisan_config_cache';

    public const TYPE_ARTISAN_ROUTE_CACHE = 'artisan_route_cache';

    public const TYPE_ARTISAN_VIEW_CACHE = 'artisan_view_cache';

    public const TYPE_ARTISAN_OPTIMIZE = 'artisan_optimize';

    public const TYPE_CUSTOM = 'custom';

    protected $fillable = [
        'site_id',
        'sort_order',
        'step_type',
        'custom_command',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_COMPOSER_INSTALL => 'Composer install (no dev)',
            self::TYPE_NPM_CI => 'npm ci',
            self::TYPE_NPM_INSTALL => 'npm install',
            self::TYPE_NPM_RUN => 'npm run … (script in field below)',
            self::TYPE_ARTISAN_MIGRATE => 'php artisan migrate --force',
            self::TYPE_ARTISAN_CONFIG_CACHE => 'php artisan config:cache',
            self::TYPE_ARTISAN_ROUTE_CACHE => 'php artisan route:cache',
            self::TYPE_ARTISAN_VIEW_CACHE => 'php artisan view:cache',
            self::TYPE_ARTISAN_OPTIMIZE => 'php artisan optimize',
            self::TYPE_CUSTOM => 'Custom shell command',
        ];
    }
}
