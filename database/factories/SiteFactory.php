<?php

namespace Database\Factories;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'server_id' => Server::factory(),
            'user_id' => User::factory(),
            'organization_id' => null,
            'name' => $name,
            'slug' => str(fake()->slug(2))->slug(),
            'type' => SiteType::Php,
            'document_root' => '/var/www/app/public',
            'repository_path' => '/var/www/app',
            'php_version' => '8.3',
            'app_port' => null,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'webhook_secret' => Str::random(48),
        ];
    }
}
