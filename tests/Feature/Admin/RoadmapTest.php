<?php

declare(strict_types=1);

use App\Modules\Roadmap\Livewire\Admin\Index as AdminRoadmapIndex;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Modules\Roadmap\Models\RoadmapItem;
use App\Models\RoadmapSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guest cannot access admin roadmap', function () {
    $this->get(route('admin.roadmap.index'))->assertRedirect(route('login', absolute: false));
});

test('authenticated user can manage roadmap in testing environment', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->call('openCreateItemModal')
        ->set('itemTitle', 'Fleet deploy contracts')
        ->set('itemSummary', 'Cross-site contract checks')
        ->set('itemStatus', RoadmapItem::STATUS_PLANNED)
        ->set('itemArea', 'platform')
        ->set('itemIsPublished', true)
        ->call('saveItem')
        ->assertHasNoErrors();

    expect(RoadmapItem::query()->where('title', 'Fleet deploy contracts')->exists())->toBeTrue();

    expect(AuditLog::query()->where('action', 'roadmap.item.created')->exists())->toBeTrue();
});

test('admin can update and delete roadmap items', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $item = RoadmapItem::factory()->create(['title' => 'Old title']);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->call('openEditItemModal', $item->id)
        ->set('itemTitle', 'Updated title')
        ->call('saveItem')
        ->assertHasNoErrors();

    expect($item->fresh()?->title)->toBe('Updated title');

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->call('requestDeleteItem', $item->id)
        ->call('confirmActionModal');

    expect(RoadmapItem::query()->find($item->id))->toBeNull();
    expect(AuditLog::query()->where('action', 'roadmap.item.deleted')->exists())->toBeTrue();
});

test('admin can review suggestions and promote to draft item', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $suggestion = RoadmapSuggestion::factory()->create([
        'title' => 'Add roadmap voting',
        'description' => 'Let users upvote ideas.',
    ]);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->set('tab', 'suggestions')
        ->call('markSuggestionReviewed', $suggestion->id);

    expect($suggestion->fresh()?->status)->toBe(RoadmapSuggestion::STATUS_REVIEWED);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->call('openPromoteSuggestionModal', $suggestion->id)
        ->set('itemIsPublished', false)
        ->call('saveItem')
        ->assertHasNoErrors();

    expect(RoadmapItem::query()->where('title', 'Add roadmap voting')->where('is_published', false)->exists())->toBeTrue();
});

test('admin suggestions tab shows inbox rows', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    RoadmapSuggestion::factory()->create([
        'title' => 'Inbox suggestion',
        'email' => 'inbox@example.com',
    ]);

    Livewire::actingAs($user)
        ->test(AdminRoadmapIndex::class)
        ->set('tab', 'suggestions')
        ->assertSee('Inbox suggestion')
        ->assertSee('inbox@example.com');
});
