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
            'runtime' => 'php',
            'runtime_version' => '8.3',
            'app_port' => null,
            'build_command' => null,
            'status' => Site::STATUS_PENDING,
            'ssl_status' => Site::SSL_NONE,
            'git_branch' => 'main',
            'webhook_secret' => Str::random(48),
            'deploy_strategy' => 'simple',
            'releases_to_keep' => 5,
            'laravel_scheduler' => false,
            'deployment_environment' => 'production',
            'restart_supervisor_programs_after_deploy' => false,
        ];
    }

    public function custom(): self
    {
        return $this->state(fn (array $attrs) => [
            'type' => SiteType::Custom,
            'runtime' => null,
            'runtime_version' => null,
            'document_root' => null,
            'repository_path' => '/home/forge/'.($attrs['slug'] ?? 'custom-site'),
            'status' => Site::STATUS_CUSTOM_ACTIVE,
            'ssl_status' => Site::SSL_NONE,
            'git_repository_url' => 'git@github.com:example/custom.git',
            'git_branch' => 'main',
            'deploy_strategy' => 'simple',
        ]);
    }

    public function customNoRepo(): self
    {
        return $this->custom()->state(fn () => [
            'git_repository_url' => null,
            'git_branch' => null,
        ]);
    }
}
