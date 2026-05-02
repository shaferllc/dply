<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Sites\SiteSystemdUnitBuilder;
use PHPUnit\Framework\TestCase;

class SiteSystemdUnitBuilderTest extends TestCase
{
    public function test_web_unit_is_null_for_php_runtime(): void
    {
        $site = new Site([
            'runtime' => 'php',
            'slug' => 'laravel-app',
            'start_command' => 'php-fpm',
            'internal_port' => 30000,
        ]);

        $this->assertNull((new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply'));
    }

    public function test_web_unit_is_null_for_static_runtime(): void
    {
        $site = new Site([
            'runtime' => 'static',
            'slug' => 'docs-site',
        ]);

        $this->assertNull((new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply'));
    }

    public function test_web_unit_is_null_when_start_command_is_empty(): void
    {
        $site = new Site([
            'runtime' => 'node',
            'slug' => 'no-cmd',
            'start_command' => '',
        ]);

        $this->assertNull((new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply'));
    }

    public function test_web_unit_renders_unit_for_node_site(): void
    {
        $site = new Site([
            'runtime' => 'node',
            'slug' => 'jobs-app',
            'start_command' => 'npm start',
            'internal_port' => 30007,
            'repository_path' => '/var/www/jobs-app',
            'deploy_strategy' => 'simple',
        ]);

        $unit = (new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply');

        $this->assertNotNull($unit);
        $this->assertStringContainsString('Description=Dply site jobs-app (web)', $unit);
        $this->assertStringContainsString('User=dply', $unit);
        $this->assertStringContainsString('Group=dply', $unit);
        $this->assertStringContainsString('WorkingDirectory=/var/www/jobs-app', $unit);
        $this->assertStringContainsString('Environment=PORT=30007', $unit);
        $this->assertStringContainsString('ExecStart=npm start', $unit);
        $this->assertStringContainsString('Restart=on-failure', $unit);
        $this->assertStringContainsString('After=network-online.target', $unit);
        $this->assertStringContainsString('WantedBy=multi-user.target', $unit);
    }

    public function test_web_unit_uses_atomic_release_current_symlink(): void
    {
        $site = new Site([
            'runtime' => 'python',
            'slug' => 'fastapi-svc',
            'start_command' => 'uvicorn main:app --host 0.0.0.0 --port 8000',
            'internal_port' => 30002,
            'repository_path' => '/var/www/fastapi-svc',
            'deploy_strategy' => 'atomic',
        ]);

        $unit = (new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply');

        $this->assertNotNull($unit);
        $this->assertStringContainsString('WorkingDirectory=/var/www/fastapi-svc/current', $unit);
    }

    public function test_web_unit_falls_back_to_app_port_then_default_when_internal_port_missing(): void
    {
        $site = new Site([
            'runtime' => 'node',
            'slug' => 'legacy',
            'start_command' => 'node server.js',
            'internal_port' => null,
            'app_port' => 4001,
            'repository_path' => '/var/www/legacy',
        ]);

        $unit = (new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply');

        $this->assertNotNull($unit);
        $this->assertStringContainsString('Environment=PORT=4001', $unit);
    }

    public function test_web_unit_omits_port_environment_when_no_port_set(): void
    {
        $site = new Site([
            'runtime' => 'go',
            'slug' => 'no-port',
            'start_command' => './bin/app',
            'internal_port' => null,
            'app_port' => null,
            'repository_path' => '/var/www/no-port',
        ]);

        $unit = (new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply');

        $this->assertNotNull($unit);
        $this->assertStringNotContainsString('Environment=PORT', $unit);
    }

    public function test_web_unit_falls_back_to_var_www_slug_when_repository_path_unset(): void
    {
        $site = new Site([
            'runtime' => 'node',
            'slug' => 'autoplaced',
            'start_command' => 'node server.js',
            'internal_port' => 30001,
            'repository_path' => null,
        ]);

        $unit = (new SiteSystemdUnitBuilder)->buildWebUnit($site, 'dply');

        $this->assertNotNull($unit);
        $this->assertStringContainsString('WorkingDirectory=/var/www/autoplaced', $unit);
    }

    public function test_process_unit_renders_for_a_worker(): void
    {
        $site = new Site([
            'runtime' => 'node',
            'slug' => 'queue-app',
            'repository_path' => '/var/www/queue-app',
        ]);
        $process = new SiteProcess([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'worker',
            'command' => 'npm run worker',
        ]);

        $unit = (new SiteSystemdUnitBuilder)->buildProcessUnit($site, $process, 'dply');

        $this->assertNotNull($unit);
        $this->assertStringContainsString('Description=Dply site queue-app (worker)', $unit);
        $this->assertStringContainsString('ExecStart=npm run worker', $unit);
        $this->assertStringNotContainsString('Environment=PORT', $unit);
    }

    public function test_process_unit_is_null_when_command_missing(): void
    {
        $site = new Site([
            'runtime' => 'node',
            'slug' => 'queue-app',
        ]);
        $process = new SiteProcess([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'web',
            'command' => null,
        ]);

        $unit = (new SiteSystemdUnitBuilder)->buildProcessUnit($site, $process, 'dply');

        $this->assertNull($unit);
    }

    public function test_unit_names_are_id_scoped_and_filesystem_safe(): void
    {
        $site = new Site;
        $site->id = '01H77NABCDEF1234ABCD';
        $site->slug = 'app';

        $process = new SiteProcess([
            'name' => 'celery beat',
        ]);

        $builder = new SiteSystemdUnitBuilder;
        $this->assertSame('dply-site-01H77NABCDEF1234ABCD.service', $builder->webUnitName($site));
        $this->assertSame(
            'dply-site-01H77NABCDEF1234ABCD-celery-beat.service',
            $builder->processUnitName($site, $process),
        );
    }
}
