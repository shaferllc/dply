<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\ErrorEvent;
use App\Support\Errors\ErrorEventActions;
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
 *
 * Livewire exposes get<Name>Property() methods as $this-><name> in PHP and
 * Blade (the pre-#[Computed] convention). PHPStan cannot see that magic,
 * so the contract is stated here.
 *
 * @property-read array<string, int> $facets
 */
trait SurfacesErrorStream
{
    #[Url(as: 'dismissed', except: false)]
    public bool $showDismissed = false;

    #[Url(as: 'cat', except: '')]
    public string $category = '';

    /**
     * The ErrorEvent query scoped to this view's entity (server or site).
     *
     * @return Builder<ErrorEvent>
     */
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
        $this->errorActions()->dismiss($this->scopedErrors(), $id, (string) auth()->id() ?: null);
    }

    public function restore(string $id): void
    {
        $this->authorizeErrorAccess();
        $this->errorActions()->restore($this->scopedErrors(), $id);
    }

    public function dismissAll(): void
    {
        $this->authorizeErrorAccess();
        $this->errorActions()->dismissAll($this->scopedErrors(), (string) auth()->id() ?: null);
        $this->toastSuccess(__('All errors dismissed.'));
    }

    public function retry(string $id): void
    {
        $this->authorizeErrorAccess();
        $event = $this->scopedErrors()->whereKey($id)->first();
        if (! $event instanceof ErrorEvent) {
            return;
        }

        if ($this->errorActions()->retry($event, (string) auth()->id() ?: null)) {
            $this->toastSuccess(__('Retrying — a new run was queued. Watch its workspace for progress.'));
        } else {
            $this->toastError(__('This error can’t be retried from here — open it to re-run at the source.'));
        }
    }

    /** Apply a recognized remediation for an error. Defaults to the recommended action. */
    public function applyRemediation(string $id, ?string $actionKey = null): void
    {
        $this->authorizeErrorAccess();

        $event = $this->scopedErrors()->whereKey($id)->first();
        if (! $event instanceof ErrorEvent) {
            $this->toastError(__('No known fix for this error.'));

            return;
        }

        match ($this->errorActions()->applyRemediation($event, $actionKey, (string) auth()->id() ?: null)) {
            'applied' => $this->toastSuccess(__('Applying the fix — it resolves this error when it finishes.')),
            'no_fix' => $this->toastError(__('No known fix for this error.')),
            'stale_action' => $this->toastError(__('That fix is no longer available.')),
            'manual' => $this->toastError(__('This fix has to be applied by hand — open the error for the steps.')),
            'no_server' => $this->toastError(__('This error isn’t tied to a server, so it can’t be fixed automatically.')),
        };
    }

    protected function errorActions(): ErrorEventActions
    {
        return app(ErrorEventActions::class);
    }

    /**
     * @return LengthAwarePaginator<int, ErrorEvent>
     */
    public function getEventsProperty(): LengthAwarePaginator
    {
        $events = $this->scopedErrors()
            ->with('server:id,name')
            ->when(! $this->showDismissed, fn ($q) => $q->whereNull('dismissed_at'))
            ->when($this->category !== '', fn ($q) => $q->where('category', $this->category))
            ->orderByDesc('occurred_at')
            ->paginate(25);

        // When the stream is unfiltered, its total IS the undismissed count, so
        // hand it to the host (e.g. to prime the workspace nav badge) and avoid a
        // second identical count() elsewhere on the page.
        if (! $this->showDismissed && $this->category === '') {
            $this->shareStreamTotal($events->total());
        }

        return $events;
    }

    /**
     * Total undismissed errors in scope. Derived from the per-category facet
     * counts (already computed for the filter chips) so it costs no extra query.
     */
    public function getOpenCountProperty(): int
    {
        return array_sum($this->facets);
    }

    /**
     * Hook for the host to reuse the unfiltered stream total elsewhere (the
     * server view primes its nav badge). No-op by default — the site view has no
     * nav badge to feed.
     */
    protected function shareStreamTotal(int $total): void {}

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
