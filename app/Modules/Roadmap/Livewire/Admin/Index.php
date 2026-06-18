<?php

declare(strict_types=1);

namespace App\Modules\Roadmap\Livewire\Admin;

use App\Livewire\Admin\Concerns\AuthorizesPlatformAdmin;
use App\Modules\Roadmap\Livewire\Admin\Concerns\ManagesRoadmapReleases;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Modules\Roadmap\Models\RoadmapItem;
use App\Models\RoadmapRelease;
use App\Models\RoadmapSuggestion;
use App\Models\User;
use App\Modules\Roadmap\Services\RoadmapSuggestionNotifier;
use App\Modules\Roadmap\Support\RoadmapQuarter;
use App\Modules\Roadmap\Support\RoadmapReleaseTrain;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use AuthorizesPlatformAdmin;
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesRoadmapReleases;
    use WithPagination;

    #[Url(as: 'tab', except: 'items')]
    public string $tab = 'items';

    #[Url(as: 'q', except: '')]
    public string $suggestionSearch = '';

    #[Url(as: 'status', except: '')]
    public string $suggestionStatusFilter = '';

    public bool $showItemModal = false;

    public ?string $editingItemId = null;

    public string $itemTitle = '';

    public string $itemSummary = '';

    public string $itemDescription = '';

    public string $itemStatus = RoadmapItem::STATUS_PLANNED;

    public string $itemArea = '';

    public string $itemTargetQuarter = '';

    public string $itemTargetReleaseId = '';

    public string $itemShippedReleaseId = '';

    public bool $itemIsPublished = false;

    public ?string $promotingSuggestionId = null;

    public ?string $viewingSuggestionId = null;

    public string $suggestionAdminNotes = '';

    public function mount(): void
    {
        $this->mountAuthorizesPlatformAdmin();
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['items', 'releases', 'suggestions'], true)) {
            return;
        }

        $this->tab = $tab;
        $this->resetPage();
    }

    public function updatedSuggestionSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSuggestionStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openCreateItemModal(): void
    {
        $this->resetItemForm();
        $this->editingItemId = null;
        $this->promotingSuggestionId = null;
        $this->showItemModal = true;
    }

    public function openEditItemModal(string $itemId): void
    {
        $item = RoadmapItem::query()->findOrFail($itemId);
        $this->editingItemId = $item->id;
        $this->itemTitle = $item->title;
        $this->itemSummary = $item->summary ?? '';
        $this->itemDescription = $item->description ?? '';
        $this->itemStatus = $item->status;
        $this->itemArea = $item->area ?? '';
        $this->itemTargetQuarter = $item->target_quarter ?? '';
        $this->itemTargetReleaseId = $item->target_release_id ?? '';
        $this->itemShippedReleaseId = $item->shipped_release_id ?? '';
        $this->itemIsPublished = $item->is_published;
        $this->promotingSuggestionId = null;
        $this->showItemModal = true;
    }

    public function openPromoteSuggestionModal(string $suggestionId): void
    {
        $suggestion = RoadmapSuggestion::query()->findOrFail($suggestionId);
        $this->resetItemForm();
        $this->editingItemId = null;
        $this->promotingSuggestionId = $suggestion->id;
        $this->itemTitle = $suggestion->title;
        $this->itemDescription = $suggestion->description;
        $this->itemSummary = '';
        $this->itemStatus = RoadmapItem::STATUS_PLANNED;
        $this->itemArea = '';
        $this->itemIsPublished = false;
        $this->showItemModal = true;
    }

    public function closeItemModal(): void
    {
        $this->showItemModal = false;
        $this->resetItemForm();
    }

    public function saveItem(): void
    {
        Gate::authorize('viewPlatformAdmin');

        if (! filled($this->itemArea)) {
            $this->itemArea = '';
        }

        if (! filled($this->itemTargetQuarter)) {
            $this->itemTargetQuarter = '';
        }

        if (! filled($this->itemTargetReleaseId)) {
            $this->itemTargetReleaseId = '';
        }

        if (! filled($this->itemShippedReleaseId)) {
            $this->itemShippedReleaseId = '';
        }

        $validated = $this->validate([
            'itemTitle' => ['required', 'string', 'max:200'],
            'itemSummary' => ['nullable', 'string', 'max:500'],
            'itemDescription' => ['nullable', 'string', 'max:10000'],
            'itemStatus' => ['required', 'in:'.implode(',', RoadmapItem::statusKeys())],
            'itemArea' => ['nullable', 'string', 'max:32', function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && ! in_array((string) $value, RoadmapItem::areaKeys(), true)) {
                    $fail(__('The selected area is invalid.'));
                }
            }],
            'itemTargetQuarter' => ['nullable', 'string', 'max:16', function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && ! RoadmapQuarter::isValidKey((string) $value)) {
                    $fail(__('The selected target quarter is invalid.'));
                }
            }],
            'itemTargetReleaseId' => ['nullable', 'string', 'exists:roadmap_releases,id'],
            'itemShippedReleaseId' => ['nullable', 'string', 'exists:roadmap_releases,id'],
            'itemIsPublished' => ['boolean'],
        ]);

        $area = filled($validated['itemArea'] ?? null) ? $validated['itemArea'] : null;
        $targetQuarter = filled($validated['itemTargetQuarter'] ?? null) ? $validated['itemTargetQuarter'] : null;
        $targetReleaseId = filled($validated['itemTargetReleaseId'] ?? null) ? $validated['itemTargetReleaseId'] : null;
        $shippedReleaseId = filled($validated['itemShippedReleaseId'] ?? null) ? $validated['itemShippedReleaseId'] : null;

        if ($validated['itemStatus'] === RoadmapItem::STATUS_SHIPPED && $shippedReleaseId === null && $targetReleaseId !== null) {
            $shippedReleaseId = $targetReleaseId;
        }

        if ($validated['itemStatus'] !== RoadmapItem::STATUS_SHIPPED) {
            $shippedReleaseId = null;
        }

        $shippedAt = $validated['itemStatus'] === RoadmapItem::STATUS_SHIPPED
            ? now()->toDateString()
            : null;

        if ($this->editingItemId) {
            $item = RoadmapItem::query()->findOrFail($this->editingItemId);
            $oldValues = $item->only(['title', 'summary', 'description', 'status', 'area', 'is_published', 'shipped_at']);

            if ($validated['itemStatus'] !== RoadmapItem::STATUS_SHIPPED) {
                $shippedAt = null;
            } elseif ($item->shipped_at !== null) {
                $shippedAt = $item->shipped_at->toDateString();
            }

            $item->update([
                'title' => trim($validated['itemTitle']),
                'summary' => filled($validated['itemSummary'] ?? null) ? trim((string) $validated['itemSummary']) : null,
                'description' => filled($validated['itemDescription'] ?? null) ? trim((string) $validated['itemDescription']) : null,
                'status' => $validated['itemStatus'],
                'area' => $area,
                'target_quarter' => $targetQuarter,
                'target_release_id' => $targetReleaseId,
                'shipped_release_id' => $shippedReleaseId,
                'is_published' => (bool) $validated['itemIsPublished'],
                'shipped_at' => $shippedAt,
            ]);

            $this->logRoadmapAudit('roadmap.item.updated', $item, $oldValues, $item->fresh()?->only([
                'title', 'summary', 'description', 'status', 'area', 'target_quarter', 'target_release_id', 'shipped_release_id', 'is_published', 'shipped_at',
            ]));
            $this->toastSuccess(__('Roadmap item updated.'));
        } else {
            $sortOrder = (int) RoadmapItem::query()
                ->where('status', $validated['itemStatus'])
                ->max('sort_order') + 1;

            $item = RoadmapItem::query()->create([
                'title' => trim($validated['itemTitle']),
                'summary' => filled($validated['itemSummary'] ?? null) ? trim((string) $validated['itemSummary']) : null,
                'description' => filled($validated['itemDescription'] ?? null) ? trim((string) $validated['itemDescription']) : null,
                'status' => $validated['itemStatus'],
                'area' => $area,
                'target_quarter' => $targetQuarter,
                'target_release_id' => $targetReleaseId,
                'shipped_release_id' => $shippedReleaseId,
                'sort_order' => $sortOrder,
                'is_published' => (bool) $validated['itemIsPublished'],
                'shipped_at' => $shippedAt,
            ]);

            $this->logRoadmapAudit('roadmap.item.created', $item, null, $item->only([
                'title', 'summary', 'description', 'status', 'area', 'target_quarter', 'target_release_id', 'shipped_release_id', 'is_published', 'shipped_at',
            ]));
            $this->toastSuccess(__('Roadmap item created.'));

            if ($this->promotingSuggestionId !== null) {
                $this->linkSuggestionToItem($this->promotingSuggestionId, $item);
            }
        }

        $this->closeItemModal();
    }

    public function requestDeleteItem(string $itemId): void
    {
        $item = RoadmapItem::query()->findOrFail($itemId);

        $this->openConfirmActionModal(
            method: 'deleteItem',
            arguments: [$itemId],
            title: __('Delete roadmap item'),
            message: __('Delete this roadmap item permanently? It will disappear from the public board if published.'),
            confirmLabel: __('Delete item'),
            destructive: true,
            details: [
                ['label' => __('Title'), 'value' => $item->title],
            ],
        );
    }

    public function deleteItem(string $itemId): void
    {
        Gate::authorize('viewPlatformAdmin');

        $item = RoadmapItem::query()->findOrFail($itemId);
        $oldValues = $item->only(['title', 'status', 'is_published']);

        $this->logRoadmapAudit('roadmap.item.deleted', $item, $oldValues, null);
        $item->delete();

        $this->toastSuccess(__('Roadmap item deleted.'));
    }

    public function moveItem(string $itemId, string $direction): void
    {
        Gate::authorize('viewPlatformAdmin');

        $item = RoadmapItem::query()->findOrFail($itemId);
        $siblings = RoadmapItem::query()
            ->where('status', $item->status)
            ->ordered()
            ->get();

        $index = $siblings->search(fn (RoadmapItem $candidate): bool => $candidate->id === $item->id);
        if ($index === false) {
            return;
        }

        $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if (! isset($siblings[$swapIndex])) {
            return;
        }

        $other = $siblings[$swapIndex];
        $currentOrder = $item->sort_order;
        $item->update(['sort_order' => $other->sort_order]);
        $other->update(['sort_order' => $currentOrder]);
    }

    /**
     * @param  list<string>  $orderedIds
     */
    public function reorderItems(string $status, array $orderedIds): void
    {
        $columns = [];
        foreach (RoadmapItem::statusKeys() as $statusKey) {
            $columns[$statusKey] = RoadmapItem::query()
                ->where('status', $statusKey)
                ->ordered()
                ->pluck('id')
                ->all();
        }

        $columns[$status] = array_values(array_unique(array_filter(
            $orderedIds,
            fn (mixed $id): bool => is_string($id) && $id !== '',
        )));

        $this->syncRoadmapColumns($columns);
    }

    /**
     * @param  array<string, list<string>>  $columns
     */
    public function syncRoadmapColumns(array $columns): void
    {
        Gate::authorize('viewPlatformAdmin');

        $validStatuses = RoadmapItem::statusKeys();

        foreach (array_keys($columns) as $status) {
            if (! in_array($status, $validStatuses, true)) {
                return;
            }
        }

        foreach ($validStatuses as $status) {
            if (! array_key_exists($status, $columns)) {
                $columns[$status] = [];
            }
        }

        foreach ($columns as $status => $orderedIds) {
            $orderedIds = array_values(array_unique(array_filter(
                $orderedIds,
                fn (mixed $id): bool => is_string($id) && $id !== '',
            )));

            foreach ($orderedIds as $index => $id) {
                $item = RoadmapItem::query()->find($id);
                if ($item === null) {
                    continue;
                }

                $updates = ['sort_order' => $index];

                if ($item->status !== $status) {
                    $updates['status'] = $status;

                    if ($status === RoadmapItem::STATUS_SHIPPED) {
                        if ($item->shipped_at === null) {
                            $updates['shipped_at'] = now()->toDateString();
                        }

                        if ($item->shipped_release_id === null && $item->target_release_id !== null) {
                            $updates['shipped_release_id'] = $item->target_release_id;
                        }
                    } else {
                        $updates['shipped_at'] = null;
                        $updates['shipped_release_id'] = null;
                    }
                }

                $item->update($updates);
            }
        }
    }

    public function openSuggestion(string $suggestionId): void
    {
        $suggestion = RoadmapSuggestion::query()->findOrFail($suggestionId);
        $this->viewingSuggestionId = $suggestion->id;
        $this->suggestionAdminNotes = $suggestion->admin_notes ?? '';
    }

    public function closeSuggestion(): void
    {
        $this->viewingSuggestionId = null;
        $this->suggestionAdminNotes = '';
    }

    public function saveSuggestionNotes(): void
    {
        Gate::authorize('viewPlatformAdmin');

        if ($this->viewingSuggestionId === null) {
            return;
        }

        $this->validate([
            'suggestionAdminNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        $suggestion = RoadmapSuggestion::query()->findOrFail($this->viewingSuggestionId);
        $oldValues = ['admin_notes' => $suggestion->admin_notes, 'status' => $suggestion->status];

        $suggestion->update([
            'admin_notes' => filled($this->suggestionAdminNotes) ? trim($this->suggestionAdminNotes) : null,
        ]);

        $this->logRoadmapAudit('roadmap.suggestion.updated', $suggestion, $oldValues, [
            'admin_notes' => $suggestion->admin_notes,
            'status' => $suggestion->status,
        ]);

        $this->toastSuccess(__('Suggestion notes saved.'));
    }

    public function markSuggestionReviewed(string $suggestionId): void
    {
        $this->updateSuggestionStatus($suggestionId, RoadmapSuggestion::STATUS_REVIEWED);
    }

    public function markSuggestionDeclined(string $suggestionId): void
    {
        $this->updateSuggestionStatus($suggestionId, RoadmapSuggestion::STATUS_DECLINED);
    }

    public function render(): View
    {
        $this->authorizePlatformAdmin();

        $items = RoadmapItem::query()
            ->with(['sourceSuggestions', 'targetRelease', 'shippedRelease'])
            ->ordered()
            ->get()
            ->groupBy('status');
        $releases = RoadmapRelease::query()->ordered()->withCount(['targetItems', 'shippedItems'])->get();
        $newSuggestionCount = RoadmapSuggestion::query()->where('status', RoadmapSuggestion::STATUS_NEW)->count();

        $suggestions = $this->tab === 'suggestions'
            ? RoadmapSuggestion::query()
                ->with('promotedRoadmapItem')
                ->search($this->suggestionSearch)
                ->status($this->suggestionStatusFilter)
                ->latest('created_at')
                ->paginate(20)
            : null;

        $viewingSuggestion = $this->viewingSuggestionId
            ? RoadmapSuggestion::query()->with('promotedRoadmapItem')->find($this->viewingSuggestionId)
            : null;

        return view('livewire.admin.roadmap.index', [
            'itemsByStatus' => $items,
            'statusLabels' => config('roadmap.statuses', []),
            'areaLabels' => config('roadmap.areas', []),
            'quarterOptions' => RoadmapQuarter::options(),
            'releaseOptions' => $releases,
            'releaseSlugOptions' => RoadmapReleaseTrain::upcomingOptions(18),
            'releases' => $releases,
            'suggestions' => $suggestions,
            'suggestionStatusLabels' => config('roadmap.suggestion_statuses', []),
            'newSuggestionCount' => $newSuggestionCount,
            'viewingSuggestion' => $viewingSuggestion,
        ]);
    }

    private function updateSuggestionStatus(string $suggestionId, string $status): void
    {
        Gate::authorize('viewPlatformAdmin');

        $suggestion = RoadmapSuggestion::query()->findOrFail($suggestionId);
        $oldValues = ['status' => $suggestion->status];

        $suggestion->update(['status' => $status]);

        $this->logRoadmapAudit('roadmap.suggestion.updated', $suggestion, $oldValues, [
            'status' => $suggestion->status,
        ]);

        $notifier = app(RoadmapSuggestionNotifier::class);
        if ($status === RoadmapSuggestion::STATUS_REVIEWED) {
            $notifier->notifyReviewed($suggestion);
        } elseif ($status === RoadmapSuggestion::STATUS_DECLINED) {
            $notifier->notifyDeclined($suggestion);
        }

        if ($this->viewingSuggestionId === $suggestionId) {
            $this->closeSuggestion();
        }

        $this->toastSuccess(__('Suggestion marked :status.', ['status' => $suggestion->statusLabel()]));
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function logRoadmapAudit(string $action, RoadmapItem|RoadmapSuggestion|RoadmapRelease $subject, ?array $oldValues, ?array $newValues): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $organization = $user->currentOrganization() ?? $user->organizations()->first();
        if (! $organization instanceof Organization) {
            return;
        }

        AuditLog::log(
            organization: $organization,
            user: $user,
            action: $action,
            subject: $subject,
            oldValues: $oldValues,
            newValues: $newValues,
        );
    }

    private function linkSuggestionToItem(string $suggestionId, RoadmapItem $item): void
    {
        $suggestion = RoadmapSuggestion::query()->find($suggestionId);
        if (! $suggestion instanceof RoadmapSuggestion) {
            return;
        }

        $oldValues = [
            'status' => $suggestion->status,
            'promoted_roadmap_item_id' => $suggestion->promoted_roadmap_item_id,
        ];

        $suggestion->update([
            'promoted_roadmap_item_id' => $item->id,
            'status' => RoadmapSuggestion::STATUS_REVIEWED,
        ]);

        $this->logRoadmapAudit('roadmap.suggestion.promoted', $suggestion, $oldValues, [
            'status' => $suggestion->status,
            'promoted_roadmap_item_id' => $item->id,
        ]);

        app(RoadmapSuggestionNotifier::class)->notifyPromoted($suggestion->fresh() ?? $suggestion, $item);
    }

    private function resetItemForm(): void
    {
        $this->editingItemId = null;
        $this->itemTitle = '';
        $this->itemSummary = '';
        $this->itemDescription = '';
        $this->itemStatus = RoadmapItem::STATUS_PLANNED;
        $this->itemArea = '';
        $this->itemTargetQuarter = '';
        $this->itemTargetReleaseId = '';
        $this->itemShippedReleaseId = '';
        $this->itemIsPublished = false;
        $this->promotingSuggestionId = null;
        $this->resetValidation();
    }
}
