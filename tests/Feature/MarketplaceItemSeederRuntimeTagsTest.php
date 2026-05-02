<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketplaceItem;
use Database\Seeders\MarketplaceItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceItemSeederRuntimeTagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MarketplaceItemSeeder::class);
    }

    public function test_seeder_tags_php_recipes_with_runtime_php(): void
    {
        foreach (['nginx-laravel-php', 'deploy-laravel', 'nginx-php-generic', 'deploy-php-fpm-reload'] as $slug) {
            $item = MarketplaceItem::query()->where('slug', $slug)->firstOrFail();
            $this->assertContains('php', $item->runtimes ?? [], "{$slug} should be tagged php");
        }
    }

    public function test_seeder_tags_laravel_recipes_with_framework_laravel(): void
    {
        foreach (['nginx-laravel-php', 'deploy-laravel-migrate-only', 'deploy-laravel-storage-link'] as $slug) {
            $item = MarketplaceItem::query()->where('slug', $slug)->firstOrFail();
            $this->assertContains('laravel', $item->frameworks ?? [], "{$slug} should be tagged laravel");
        }
    }

    public function test_seeder_tags_rails_recipes_with_runtime_ruby_and_framework_rails(): void
    {
        foreach (['deploy-rails', 'deploy-rails-db-migrate-only'] as $slug) {
            $item = MarketplaceItem::query()->where('slug', $slug)->firstOrFail();
            $this->assertContains('ruby', $item->runtimes ?? []);
            $this->assertContains('rails', $item->frameworks ?? []);
        }
    }

    public function test_seeder_tags_django_recipes_with_runtime_python_and_framework_django(): void
    {
        $django = MarketplaceItem::query()->where('slug', 'deploy-django-prod')->firstOrFail();
        $this->assertContains('python', $django->runtimes ?? []);
        $this->assertContains('django', $django->frameworks ?? []);
    }

    public function test_seeder_tags_node_recipes_with_runtime_node(): void
    {
        foreach (['nginx-node-reverse-proxy', 'deploy-npm-build-prod', 'deploy-pnpm-ci-build'] as $slug) {
            $item = MarketplaceItem::query()->where('slug', $slug)->firstOrFail();
            $this->assertContains('node', $item->runtimes ?? []);
        }
    }

    public function test_seeder_includes_curated_non_php_process_recipes(): void
    {
        foreach ([
            'process-node-bullmq-worker' => 'node',
            'process-python-celery-worker' => 'python',
            'process-python-celery-beat' => 'python',
            'process-ruby-sidekiq' => 'ruby',
            'process-laravel-horizon' => 'php',
            'process-laravel-scheduler' => 'php',
        ] as $slug => $expectedRuntime) {
            $item = MarketplaceItem::query()->where('slug', $slug)->firstOrFail();
            $this->assertContains($expectedRuntime, $item->runtimes ?? []);
            $this->assertNotEmpty($item->payload['command'] ?? null);
        }
    }

    public function test_universal_items_remain_untagged(): void
    {
        // Guides / notification integrations / generic snippets should
        // surface for every runtime, so they stay tag-free.
        foreach (['guide-first-server', 'integration-slack-webhook', 'guide-api-keys'] as $slug) {
            $item = MarketplaceItem::query()->where('slug', $slug)->firstOrFail();
            $this->assertNull($item->runtimes, "{$slug} should remain runtime-agnostic");
        }
    }

    public function test_for_runtime_scope_returns_only_php_recipes_plus_universal(): void
    {
        $phpItems = MarketplaceItem::query()->forRuntime('php')->whereNotNull('runtimes')->pluck('slug');

        $this->assertTrue($phpItems->contains('process-laravel-horizon'));
        $this->assertFalse($phpItems->contains('process-ruby-sidekiq'));
        $this->assertFalse($phpItems->contains('process-node-bullmq-worker'));
    }

    public function test_for_runtime_scope_for_ruby_excludes_node_python_php(): void
    {
        $rubyItems = MarketplaceItem::query()->forRuntime('ruby')->whereNotNull('runtimes')->pluck('slug');

        $this->assertTrue($rubyItems->contains('process-ruby-sidekiq'));
        $this->assertTrue($rubyItems->contains('deploy-rails'));
        $this->assertFalse($rubyItems->contains('process-laravel-horizon'));
        $this->assertFalse($rubyItems->contains('process-node-bullmq-worker'));
    }
}
