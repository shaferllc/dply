<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\MarketplaceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceItemRuntimeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_universal_item_appears_for_any_runtime(): void
    {
        $universal = MarketplaceItem::factory()->create([
            'slug' => 'redis-stats',
            'runtimes' => null,
        ]);

        foreach (['php', 'node', 'python', 'ruby', 'go'] as $runtime) {
            $matched = MarketplaceItem::query()
                ->forRuntime($runtime)
                ->pluck('id')
                ->all();

            $this->assertContains($universal->id, $matched, "universal item should match runtime `{$runtime}`");
        }
    }

    public function test_empty_array_runtimes_treated_as_universal(): void
    {
        $item = MarketplaceItem::factory()->create([
            'slug' => 'cloudflare-warp',
            'runtimes' => [],
        ]);

        $matched = MarketplaceItem::query()->forRuntime('php')->pluck('id')->all();

        $this->assertContains($item->id, $matched);
    }

    public function test_runtime_specific_item_only_appears_for_that_runtime(): void
    {
        $phpOnly = MarketplaceItem::factory()->forRuntimes(['php'])->create(['slug' => 'install-horizon']);
        $rubyOnly = MarketplaceItem::factory()->forRuntimes(['ruby'])->create(['slug' => 'install-sidekiq']);

        $phpMatches = MarketplaceItem::query()->forRuntime('php')->pluck('id')->all();
        $rubyMatches = MarketplaceItem::query()->forRuntime('ruby')->pluck('id')->all();

        $this->assertContains($phpOnly->id, $phpMatches);
        $this->assertNotContains($rubyOnly->id, $phpMatches);

        $this->assertContains($rubyOnly->id, $rubyMatches);
        $this->assertNotContains($phpOnly->id, $rubyMatches);
    }

    public function test_multi_runtime_item_appears_for_each_listed_runtime(): void
    {
        $polyglot = MarketplaceItem::factory()->forRuntimes(['php', 'node'])->create(['slug' => 'tiered-cache']);

        $phpMatches = MarketplaceItem::query()->forRuntime('php')->pluck('id')->all();
        $nodeMatches = MarketplaceItem::query()->forRuntime('node')->pluck('id')->all();
        $pythonMatches = MarketplaceItem::query()->forRuntime('python')->pluck('id')->all();

        $this->assertContains($polyglot->id, $phpMatches);
        $this->assertContains($polyglot->id, $nodeMatches);
        $this->assertNotContains($polyglot->id, $pythonMatches);
    }

    public function test_for_runtime_with_null_returns_everything(): void
    {
        // Standalone marketplace page — no site/server context, should show all items.
        $php = MarketplaceItem::factory()->forRuntimes(['php'])->create(['slug' => 'a']);
        $ruby = MarketplaceItem::factory()->forRuntimes(['ruby'])->create(['slug' => 'b']);
        $universal = MarketplaceItem::factory()->create(['slug' => 'c', 'runtimes' => null]);

        $matched = MarketplaceItem::query()->forRuntime(null)->pluck('id')->all();

        $this->assertContains($php->id, $matched);
        $this->assertContains($ruby->id, $matched);
        $this->assertContains($universal->id, $matched);
    }

    public function test_framework_specific_item_only_appears_for_that_framework(): void
    {
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

        $this->assertContains($horizon->id, $laravelMatches);
        $this->assertNotContains($sidekiq->id, $laravelMatches);

        $this->assertContains($sidekiq->id, $railsMatches);
        $this->assertNotContains($horizon->id, $railsMatches);
    }

    public function test_framework_agnostic_item_appears_for_any_framework_when_runtime_matches(): void
    {
        $genericPhp = MarketplaceItem::factory()
            ->forRuntimes(['php'])
            ->create(['slug' => 'opcache-tune', 'frameworks' => null]);

        $matched = MarketplaceItem::query()
            ->forRuntime('php')
            ->forFramework('laravel')
            ->pluck('id')->all();

        $this->assertContains($genericPhp->id, $matched);
    }

    public function test_runtime_and_framework_filters_combine_with_and_semantics(): void
    {
        $laravelOnPhp = MarketplaceItem::factory()
            ->forRuntimes(['php'])
            ->forFrameworks(['laravel'])
            ->create(['slug' => 'horizon']);

        // Laravel item should NOT appear when runtime doesn't match (e.g. on a Node site).
        $nodeFilter = MarketplaceItem::query()
            ->forRuntime('node')
            ->forFramework('laravel')
            ->pluck('id')->all();

        $this->assertNotContains($laravelOnPhp->id, $nodeFilter);
    }

    public function test_default_factory_creates_universal_item(): void
    {
        $item = MarketplaceItem::factory()->create();

        $this->assertNull($item->runtimes);
        $this->assertNull($item->frameworks);
    }

    public function test_runtimes_field_round_trips_through_array_cast(): void
    {
        $item = MarketplaceItem::factory()
            ->forRuntimes(['php', 'node'])
            ->forFrameworks(['laravel'])
            ->create();

        $fresh = $item->fresh();

        $this->assertSame(['php', 'node'], $fresh->runtimes);
        $this->assertSame(['laravel'], $fresh->frameworks);
    }
}
