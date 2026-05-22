<?php

declare(strict_types=1);

namespace Tests\Feature\MarketplaceItemSeederRuntimeTagsTest;
use App\Models\MarketplaceItem;
use Database\Seeders\MarketplaceItemSeeder;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(MarketplaceItemSeeder::class);
});
test('seeder tags php recipes with runtime php', function () {
    foreach (['nginx-laravel-php', 'deploy-laravel', 'nginx-php-generic', 'deploy-php-fpm-reload'] as $slug) {
        $item = MarketplaceItem::query()->where('slug', $slug)->firstOrFail();
        expect($item->runtimes ?? [])->toContain('php', "{$slug} should be tagged php");
    }
});
test('seeder tags laravel recipes with framework laravel', function () {
    foreach (['nginx-laravel-php', 'deploy-laravel-migrate-only', 'deploy-laravel-storage-link'] as $slug) {
        $item = MarketplaceItem::query()->where('slug', $slug)->firstOrFail();
        expect($item->frameworks ?? [])->toContain('laravel', "{$slug} should be tagged laravel");
    }
});
test('seeder tags rails recipes with runtime ruby and framework rails', function () {
    foreach (['deploy-rails', 'deploy-rails-db-migrate-only'] as $slug) {
        $item = MarketplaceItem::query()->where('slug', $slug)->firstOrFail();
        expect($item->runtimes ?? [])->toContain('ruby');
        expect($item->frameworks ?? [])->toContain('rails');
    }
});
test('seeder tags django recipes with runtime python and framework django', function () {
    $django = MarketplaceItem::query()->where('slug', 'deploy-django-prod')->firstOrFail();
    expect($django->runtimes ?? [])->toContain('python');
    expect($django->frameworks ?? [])->toContain('django');
});
test('seeder tags node recipes with runtime node', function () {
    foreach (['nginx-node-reverse-proxy', 'deploy-npm-build-prod', 'deploy-pnpm-ci-build'] as $slug) {
        $item = MarketplaceItem::query()->where('slug', $slug)->firstOrFail();
        expect($item->runtimes ?? [])->toContain('node');
    }
});
test('seeder includes curated non php process recipes', function () {
    foreach ([
        'process-node-bullmq-worker' => 'node',
        'process-python-celery-worker' => 'python',
        'process-python-celery-beat' => 'python',
        'process-ruby-sidekiq' => 'ruby',
        'process-laravel-horizon' => 'php',
        'process-laravel-scheduler' => 'php',
    ] as $slug => $expectedRuntime) {
        $item = MarketplaceItem::query()->where('slug', $slug)->firstOrFail();
        expect($item->runtimes ?? [])->toContain($expectedRuntime);
        expect($item->payload['command'] ?? null)->not->toBeEmpty();
    }
});
test('universal items remain untagged', function () {
    // Guides / notification integrations / generic snippets should
    // surface for every runtime, so they stay tag-free.
    foreach (['guide-first-server', 'integration-slack-webhook', 'guide-api-keys'] as $slug) {
        $item = MarketplaceItem::query()->where('slug', $slug)->firstOrFail();
        expect($item->runtimes)->toBeNull("{$slug} should remain runtime-agnostic");
    }
});
test('for runtime scope returns only php recipes plus universal', function () {
    $phpItems = MarketplaceItem::query()->forRuntime('php')->whereNotNull('runtimes')->pluck('slug');

    expect($phpItems->contains('process-laravel-horizon'))->toBeTrue();
    expect($phpItems->contains('process-ruby-sidekiq'))->toBeFalse();
    expect($phpItems->contains('process-node-bullmq-worker'))->toBeFalse();
});
test('for runtime scope for ruby excludes node python php', function () {
    $rubyItems = MarketplaceItem::query()->forRuntime('ruby')->whereNotNull('runtimes')->pluck('slug');

    expect($rubyItems->contains('process-ruby-sidekiq'))->toBeTrue();
    expect($rubyItems->contains('deploy-rails'))->toBeTrue();
    expect($rubyItems->contains('process-laravel-horizon'))->toBeFalse();
    expect($rubyItems->contains('process-node-bullmq-worker'))->toBeFalse();
});
