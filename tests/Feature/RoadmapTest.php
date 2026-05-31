<?php

declare(strict_types=1);

use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Livewire\Roadmap\Index as RoadmapIndex;
use App\Models\RoadmapItem;
use App\Models\RoadmapSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guest can view public roadmap', function () {
    RoadmapItem::factory()->create([
        'title' => 'Public pipeline boards',
        'is_published' => true,
    ]);

    $this->withoutMiddleware([RedirectGuestsToComingSoon::class])
        ->get(route('roadmap'))
        ->assertOk()
        ->assertSee(__('Product roadmap'))
        ->assertSee('Public pipeline boards');
});

test('only published roadmap items appear on public page', function () {
    RoadmapItem::factory()->create([
        'title' => 'Visible item',
        'is_published' => true,
    ]);
    RoadmapItem::factory()->draft()->create([
        'title' => 'Hidden draft item',
    ]);

    $this->withoutMiddleware([RedirectGuestsToComingSoon::class])
        ->get(route('roadmap'))
        ->assertOk()
        ->assertSee('Visible item')
        ->assertDontSee('Hidden draft item');
});

test('guest can submit a roadmap suggestion', function () {
    RateLimiter::clear('roadmap-suggestion:'.sha1('builder@example.com|127.0.0.1'));

    Livewire::test(RoadmapIndex::class)
        ->set('suggestionEmail', 'builder@example.com')
        ->set('suggestionTitle', 'Better deploy hooks')
        ->set('suggestionDescription', 'Need webhook retries on failure.')
        ->call('submitSuggestion')
        ->assertHasNoErrors()
        ->assertSet('suggestionSubmitted', true);

    expect(RoadmapSuggestion::query()->where('email', 'builder@example.com')->exists())->toBeTrue();
});

test('roadmap suggestion validates required fields', function () {
    Livewire::test(RoadmapIndex::class)
        ->set('suggestionEmail', '')
        ->set('suggestionTitle', '')
        ->set('suggestionDescription', '')
        ->call('submitSuggestion')
        ->assertHasErrors(['suggestionEmail', 'suggestionTitle', 'suggestionDescription']);
});

test('submitted suggestions are not shown on public roadmap page', function () {
    RoadmapSuggestion::factory()->create([
        'title' => 'Secret user idea',
        'email' => 'secret@example.com',
    ]);

    $this->withoutMiddleware([RedirectGuestsToComingSoon::class])
        ->get(route('roadmap'))
        ->assertOk()
        ->assertDontSee('Secret user idea')
        ->assertDontSee('secret@example.com');
});

test('roadmap area filter limits visible items', function () {
    RoadmapItem::factory()->create([
        'title' => 'Edge CDN polish',
        'area' => 'edge',
        'is_published' => true,
    ]);
    RoadmapItem::factory()->create([
        'title' => 'Server patching UI',
        'area' => 'servers',
        'is_published' => true,
    ]);

    Livewire::test(RoadmapIndex::class)
        ->set('area', 'edge')
        ->assertSee('Edge CDN polish')
        ->assertDontSee('Server patching UI');
});
