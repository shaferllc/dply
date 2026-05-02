<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy;

use App\Models\SiteDeployStep;
use App\Services\Deploy\RuntimeAwareDeployStepDefaults;
use PHPUnit\Framework\TestCase;

class RuntimeAwareDeployStepDefaultsTest extends TestCase
{
    public function test_php_laravel_emits_composer_install_then_migrate_then_optimize(): void
    {
        $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('php', 'laravel');

        $this->assertCount(3, $steps);
        $this->assertSame(SiteDeployStep::TYPE_COMPOSER_INSTALL, $steps[0]['step_type']);
        $this->assertSame(SiteDeployStep::PHASE_BUILD, $steps[0]['phase']);
        $this->assertSame(SiteDeployStep::TYPE_ARTISAN_MIGRATE, $steps[1]['step_type']);
        $this->assertSame(SiteDeployStep::PHASE_RELEASE, $steps[1]['phase']);
        $this->assertSame(SiteDeployStep::TYPE_ARTISAN_OPTIMIZE, $steps[2]['step_type']);
        $this->assertSame(SiteDeployStep::PHASE_RELEASE, $steps[2]['phase']);
    }

    public function test_php_generic_omits_artisan_steps(): void
    {
        $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('php', null);

        $this->assertCount(1, $steps);
        $this->assertSame(SiteDeployStep::TYPE_COMPOSER_INSTALL, $steps[0]['step_type']);
    }

    public function test_node_next_adds_npm_run_build(): void
    {
        $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('node', 'next');

        $this->assertCount(2, $steps);
        $this->assertSame(SiteDeployStep::TYPE_NPM_CI, $steps[0]['step_type']);
        $this->assertSame(SiteDeployStep::TYPE_NPM_RUN, $steps[1]['step_type']);
        $this->assertSame('build', $steps[1]['custom_command']);
    }

    public function test_node_generic_omits_npm_run_build(): void
    {
        $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('node', 'node');

        $this->assertCount(1, $steps);
        $this->assertSame(SiteDeployStep::TYPE_NPM_CI, $steps[0]['step_type']);
    }

    public function test_python_django_includes_collectstatic_build_and_migrate_release(): void
    {
        $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('python', 'django');

        $this->assertCount(3, $steps);
        $commands = array_column($steps, 'custom_command');
        $this->assertContains('pip install -r requirements.txt', $commands);
        $this->assertContains('python manage.py collectstatic --noinput', $commands);
        $this->assertContains('python manage.py migrate --noinput', $commands);

        // Migrate is in release phase, others in build.
        $migrate = collect($steps)->firstWhere('custom_command', 'python manage.py migrate --noinput');
        $this->assertSame(SiteDeployStep::PHASE_RELEASE, $migrate['phase']);
    }

    public function test_ruby_rails_includes_assets_precompile_build_and_db_migrate_release(): void
    {
        $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('ruby', 'rails');

        $this->assertCount(3, $steps);
        $commands = array_column($steps, 'custom_command');
        $this->assertStringContainsString('bundle install', $commands[0]);
        $this->assertContains('bundle exec rails assets:precompile', $commands);
        $this->assertContains('bundle exec rails db:migrate', $commands);

        $migrate = collect($steps)->firstWhere('custom_command', 'bundle exec rails db:migrate');
        $this->assertSame(SiteDeployStep::PHASE_RELEASE, $migrate['phase']);
    }

    public function test_go_emits_single_build_step(): void
    {
        $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('go');

        $this->assertCount(1, $steps);
        $this->assertSame(SiteDeployStep::TYPE_CUSTOM, $steps[0]['step_type']);
        $this->assertStringContainsString('go build', $steps[0]['custom_command']);
        $this->assertSame(SiteDeployStep::PHASE_BUILD, $steps[0]['phase']);
    }

    public function test_static_jekyll_emits_jekyll_build(): void
    {
        $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('static', 'jekyll');

        $this->assertCount(1, $steps);
        $this->assertSame('bundle exec jekyll build', $steps[0]['custom_command']);
    }

    public function test_static_hugo_emits_hugo_minify(): void
    {
        $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('static', 'hugo');

        $this->assertCount(1, $steps);
        $this->assertSame('hugo --minify', $steps[0]['custom_command']);
    }

    public function test_static_plain_index_html_emits_no_steps(): void
    {
        $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('static', 'static');

        $this->assertSame([], $steps);
    }

    public function test_null_runtime_returns_empty_list(): void
    {
        $this->assertSame([], (new RuntimeAwareDeployStepDefaults)->defaultsFor(null));
    }

    public function test_unknown_runtime_returns_empty_list(): void
    {
        $this->assertSame([], (new RuntimeAwareDeployStepDefaults)->defaultsFor('cobol'));
    }

    public function test_sort_order_increases_in_declaration_order(): void
    {
        $steps = (new RuntimeAwareDeployStepDefaults)->defaultsFor('php', 'laravel');

        $orders = array_column($steps, 'sort_order');
        $sorted = $orders;
        sort($sorted);
        $this->assertSame($sorted, $orders);
        $this->assertGreaterThan($orders[0], $orders[1]);
    }
}
