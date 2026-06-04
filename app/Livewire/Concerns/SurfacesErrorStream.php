<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\ErrorEvent;
use App\Support\Errors\ErrorRetryRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * Shared behaviour for the site & server "Errors" views: the chronological
 * {@see ErrorEvent} stream with shared dismiss, category facets, and inline
 * retry. The host component supplies the scope ({@see scopedErrors()}) and the
 * authorization gate ({@see authorizeErrorAccess()}); everything else — query,
 * dismiss, retry, facets — lives here so the two views can't drift.
 *
 * Hosts must also use Livewire\WithPagination and a toast trait.
 */
trait SurfacesErrorStream
{
    #[Url(as: 'dismissed', except: false)]
    public bool $showDismissed = false;

    #[Url(as: 'cat', except: '')]
    public string $category = '';

    /** The ErrorEvent query scoped to this view's entity (server or site). */
    abstract protected function scopedErrors(): Builder;

    /** Throw if the current user can't act on this view's errors. */
    abstract protected function authorizeErrorAccess(): void;

    public function updatedShowDismissed(): void
    {
        $this->resetPage();
    }

    public function setCategory(string $category): void
    {
        $this->category = $this->category === $category ? '' : $category;
        $this->resetPage();
    }

    public function dismiss(string $id): void
    {
        $this->authorizeErrorAccess();
        $this->scopedErrors()->whereKey($id)->whereNull('dismissed_at')->update([
            'dismissed_at' => now(),
            'dismissed_by' => auth()->id(),
        ]);
    }

    public function restore(string $id): void
    {
        $this->authorizeErrorAccess();
        $this->scopedErrors()->whereKey($id)->update(['dismissed_at' => null, 'dismissed_by' => null]);
    }

    public function dismissAll(): void
    {
        $this->authorizeErrorAccess();
        $this->scopedErrors()->whereNull('dismissed_at')->update([
            'dismissed_at' => now(),
            'dismissed_by' => auth()->id(),
        ]);
        $this->toastSuccess(__('All errors dismissed.'));
    }

    public function retry(string $id, ErrorRetryRegistry $registry): void
    {
        $this->authorizeErrorAccess();
        $event = $this->scopedErrors()->whereKey($id)->first();
        if (! $event instanceof ErrorEvent) {
            return;
        }

        if ($registry->retry($event, (string) auth()->id() ?: null)) {
            $this->toastSuccess(__('Retrying — a new run was queued. Watch its workspace for progress.'));
        } else {
            $this->toastError(__('This error can’t be retried from here — open it to re-run at the source.'));
        }
    }

    public function getEventsProperty(): LengthAwarePaginator
    {
        return $this->scopedErrors()
            ->when(! $this->showDismissed, fn ($q) => $q->whereNull('dismissed_at'))
            ->when($this->category !== '', fn ($q) => $q->where('category', $this->category))
            ->orderByDesc('occurred_at')
            ->paginate(25);
    }

    public function getOpenCountProperty(): int
    {
        return $this->scopedErrors()->whereNull('dismissed_at')->count();
    }

    /**
     * Un-dismissed counts per category, for the filter chips.
     *
     * @return array<string, int>
     */
    public function getFacetsProperty(): array
    {
        return $this->scopedErrors()
            ->whereNull('dismissed_at')
            ->selectRaw('category, count(*) as c')
            ->groupBy('category')
            ->orderByDesc('c')
            ->pluck('c', 'category')
            ->all();
    }
}
