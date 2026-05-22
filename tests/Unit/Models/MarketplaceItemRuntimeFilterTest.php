<?php

declare(strict_types=1);

namespace Tests\Unit\Models\MarketplaceItemRuntimeFilterTest;
use App\Models\MarketplaceItem;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('universal item appears for any runtime', function () {
    $universal = MarketplaceItem::factory()->create([
        'slug' => 'redis-stats',
        'runtimes' => null,
    ]);

    foreach (['php', 'node', 'python', 'ruby', 'go'] as $runtime) {
        $matched = MarketplaceItem::query()
            ->forRuntime($runtime)
            ->pluck('id')
            ->all();

        expect($matched)->toContain($universal->id, "universal item should match runtime `{$runtime}`");
    }
});
test('empty array runtimes treated as universal', function () {
    $item = MarketplaceItem::factory()->create([
        'slug' => 'cloudflare-warp',
        'runtimes' => [],
    ]);

    $matched = MarketplaceItem::query()->forRuntime('php')->pluck('id')->all();

    expect($matched)->toContain($item->id);
});
test('runtime specific item only appears for that runtime', function () {
    $phpOnly = MarketplaceItem::factory()->forRuntimes(['php'])->create(['slug' => 'install-horizon']);
    $rubyOnly = MarketplaceItem::factory()->forRuntimes(['ruby'])->create(['slug' => 'install-sidekiq']);

    $phpMatches = MarketplaceItem::query()->forRuntime('php')->pluck('id')->all();
    $rubyMatches = MarketplaceItem::query()->forRuntime('ruby')->pluck('id')->all();

    expect($phpMatches)->toContain($phpOnly->id);
    expect($phpMatches)->not->toContain($rubyOnly->id);

    expect($rubyMatches)->toContain($rubyOnly->id);
    expect($rubyMatches)->not->toContain($phpOnly->id);
});
test('multi runtime item appears for each listed runtime', function () {
    $polyglot = MarketplaceItem::factory()->forRuntimes(['php', 'node'])->create(['slug' => 'tiered-cache']);

    $phpMatches = MarketplaceItem::query()->forRuntime('php')->pluck('id')->all();
    $nodeMatches = MarketplaceItem::query()->forRuntime('node')->pluck('id')->all();
    $pythonMatches = MarketplaceItem::query()->forRuntime('python')->pluck('id')->all();

    expect($phpMatches)->toContain($polyglot->id);
    expect($nodeMatches)->toContain($polyglot->id);
    expect($pythonMatches)->not->toContain($polyglot->id);
});
test('for runtime with null returns everything', function () {
    // Standalone marketplace page — no site/server context, should show all items.
    $php = MarketplaceItem::factory()->forRuntimes(['php'])->create(['slug' => 'a']);
    $ruby = MarketplaceItem::factory()->forRuntimes(['ruby'])->create(['slug' => 'b']);
    $universal = MarketplaceItem::factory()->create(['slug' => 'c', 'runtimes' => null]);

    $matched = MarketplaceItem::query()->forRuntime(null)->pluck('id')->all();

    expect($matched)->toContain($php->id);
    expect($matched)->toContain($ruby->id);
    expect($matched)->toContain($universal->id);
});
test('framework specific item only appears for that framework', function () {
    $horizon = MarketplaceItem::factory()
        ->forRuntimes(['php'])
        ->forFrameworks(['laravel'])
        ->create(['slug' => 'install-horizon']);

    $sidekiq = MarketplaceItem::factory()
        ->forRuntimes(['ruby'])
        ->forFrameworks(['rails'])
        ->create(['slug' => 'install-sidekiq']);

    $laravelMatches = MarketplaceItem::query()
        ->forRuntime('php')
        ->forFramework('laravel')
        ->pluck('id')->all();

    $railsMatches = MarketplaceItem::query()
        ->forRuntime('ruby')
        ->forFramework('rails')
        ->pluck('id')->all();

    expect($laravelMatches)->toContain($horizon->id);
    expect($laravelMatches)->not->toContain($sidekiq->id);

    expect($railsMatches)->toContain($sidekiq->id);
    expect($railsMatches)->not->toContain($horizon->id);
});
test('framework agnostic item appears for any framework when runtime matches', function () {
    $genericPhp = MarketplaceItem::factory()
        ->forRuntimes(['php'])
        ->create(['slug' => 'opcache-tune', 'frameworks' => null]);

    $matched = MarketplaceItem::query()
        ->forRuntime('php')
        ->forFramework('laravel')
        ->pluck('id')->all();

    expect($matched)->toContain($genericPhp->id);
});
test('runtime and framework filters combine with and semantics', function () {
    $laravelOnPhp = MarketplaceItem::factory()
        ->forRuntimes(['php'])
        ->forFrameworks(['laravel'])
        ->create(['slug' => 'horizon']);

    // Laravel item should NOT appear when runtime doesn't match (e.g. on a Node site).
    $nodeFilter = MarketplaceItem::query()
        ->forRuntime('node')
        ->forFramework('laravel')
        ->pluck('id')->all();

    expect($nodeFilter)->not->toContain($laravelOnPhp->id);
});
test('default factory creates universal item', function () {
    $item = MarketplaceItem::factory()->create();

    expect($item->runtimes)->toBeNull();
    expect($item->frameworks)->toBeNull();
});
test('runtimes field round trips through array cast', function () {
    $item = MarketplaceItem::factory()
        ->forRuntimes(['php', 'node'])
        ->forFrameworks(['laravel'])
        ->create();

    $fresh = $item->fresh();

    expect($fresh->runtimes)->toBe(['php', 'node']);
    expect($fresh->frameworks)->toBe(['laravel']);
});
