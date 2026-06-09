<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Roadmap\Concerns;

use App\Models\RoadmapRelease;
use App\Support\Roadmap\RoadmapReleaseTrain;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 */
trait ManagesRoadmapReleases
{
    public bool $showReleaseModal = false;

    public ?string $editingReleaseId = null;

    public string $releaseSlug = '';

    public string $releaseTitle = '';

    public string $releaseSummary = '';

    public string $releasePublishedAt = '';

    public bool $releaseIsPublished = false;

    public function openCreateReleaseModal(): void
    {
        $this->resetReleaseForm();
        $this->releaseSlug = RoadmapReleaseTrain::slugFromDate(now()->startOfMonth()->toImmutable());
        $this->releasePublishedAt = now()->toDateString();
        $this->showReleaseModal = true;
    }

    public function openEditReleaseModal(string $releaseId): void
    {
        $release = RoadmapRelease::query()->findOrFail($releaseId);
        $this->editingReleaseId = $release->id;
        $this->releaseSlug = $release->slug;
        $this->releaseTitle = $release->title ?? '';
        $this->releaseSummary = $release->summary ?? '';
        $this->releasePublishedAt = $release->published_at?->toDateString() ?? '';
        $this->releaseIsPublished = $release->is_published;
        $this->showReleaseModal = true;
    }

    public function closeReleaseModal(): void
    {
        $this->showReleaseModal = false;
        $this->resetReleaseForm();
    }

    public function saveRelease(): void
    {
        Gate::authorize('viewPlatformAdmin');

        $validated = $this->validate([
            'releaseSlug' => ['required', 'string', 'max:7', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! RoadmapReleaseTrain::isValidSlug((string) $value)) {
                    $fail(__('Use a calendar train slug like 2026-06.'));
                }
            }],
            'releaseTitle' => ['nullable', 'string', 'max:120'],
            'releaseSummary' => ['nullable', 'string', 'max:5000'],
            'releasePublishedAt' => ['nullable', 'date'],
            'releaseIsPublished' => ['boolean'],
        ]);

        $slug = (string) $validated['releaseSlug'];
        $duplicate = RoadmapRelease::query()
            ->where('slug', $slug)
            ->when($this->editingReleaseId, fn ($query) => $query->whereKeyNot($this->editingReleaseId))
            ->exists();

        if ($duplicate) {
            $this->addError('releaseSlug', __('A release train with this slug already exists.'));

            return;
        }

        $payload = [
            'slug' => $slug,
            'title' => filled($validated['releaseTitle'] ?? null) ? trim((string) $validated['releaseTitle']) : null,
            'summary' => filled($validated['releaseSummary'] ?? null) ? trim((string) $validated['releaseSummary']) : null,
            'published_at' => filled($validated['releasePublishedAt'] ?? null) ? $validated['releasePublishedAt'] : null,
            'is_published' => (bool) $validated['releaseIsPublished'],
        ];

        if ($this->editingReleaseId) {
            $release = RoadmapRelease::query()->findOrFail($this->editingReleaseId);
            $oldValues = $release->only(['slug', 'title', 'summary', 'published_at', 'is_published']);
            $release->update($payload);
            $this->logRoadmapAudit('roadmap.release.updated', $release, $oldValues, $release->fresh()?->only([
                'slug', 'title', 'summary', 'published_at', 'is_published',
            ]));
            $this->toastSuccess(__('Release train updated.'));
        } else {
            $payload['sort_order'] = (int) RoadmapRelease::query()->max('sort_order') + 1;
            $release = RoadmapRelease::query()->create($payload);
            $this->logRoadmapAudit('roadmap.release.created', $release, null, $release->only([
                'slug', 'title', 'summary', 'published_at', 'is_published',
            ]));
            $this->toastSuccess(__('Release train created.'));
        }

        $this->closeReleaseModal();
    }

    public function requestDeleteRelease(string $releaseId): void
    {
        $release = RoadmapRelease::query()->findOrFail($releaseId);

        $this->openConfirmActionModal(
            method: 'deleteRelease',
            arguments: [$releaseId],
            title: __('Delete release train'),
            message: __('Delete this release train? Linked roadmap items will keep their quarter and ship dates but lose this train assignment.'),
            confirmLabel: __('Delete release'),
            destructive: true,
            details: [
                ['label' => __('Train'), 'value' => $release->trainLabel()],
            ],
        );
    }

    public function deleteRelease(string $releaseId): void
    {
        Gate::authorize('viewPlatformAdmin');

        $release = RoadmapRelease::query()->findOrFail($releaseId);
        $oldValues = $release->only(['slug', 'title', 'is_published']);

        $this->logRoadmapAudit('roadmap.release.deleted', $release, $oldValues, null);
        $release->delete();

        $this->toastSuccess(__('Release train deleted.'));
    }

    private function resetReleaseForm(): void
    {
        $this->editingReleaseId = null;
        $this->releaseSlug = '';
        $this->releaseTitle = '';
        $this->releaseSummary = '';
        $this->releasePublishedAt = '';
        $this->releaseIsPublished = false;
        $this->resetValidation();
    }
}
