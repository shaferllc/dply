<?php

declare(strict_types=1);

namespace App\Livewire\Roadmap;

use App\Models\RoadmapItem;
use App\Models\RoadmapRelease;
use App\Models\RoadmapSuggestion;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    #[Url(history: true)]
    public string $area = 'all';

    #[Url(history: true)]
    public string $release = 'all';

    public string $suggestionName = '';

    public string $suggestionEmail = '';

    public string $suggestionTitle = '';

    public string $suggestionDescription = '';

    public bool $suggestionSubmitted = false;

    public function updatedArea(string $value): void
    {
        if ($value !== 'all' && ! in_array($value, RoadmapItem::areaKeys(), true)) {
            $this->area = 'all';
        }
    }

    public function updatedRelease(string $value): void
    {
        if ($value !== 'all' && ! RoadmapRelease::query()->published()->whereKey($value)->exists()) {
            $this->release = 'all';
        }
    }

    public function submitSuggestion(): void
    {
        $validated = $this->validate([
            'suggestionName' => ['nullable', 'string', 'max:120'],
            'suggestionEmail' => ['required', 'string', 'email', 'max:254'],
            'suggestionTitle' => ['required', 'string', 'max:200'],
            'suggestionDescription' => ['required', 'string', 'max:5000'],
        ]);

        $rateLimitKey = $this->suggestionRateLimitKey($validated['suggestionEmail']);
        $maxAttempts = (int) config('roadmap.suggestion_rate_limit.max_attempts', 3);
        $decaySeconds = (int) config('roadmap.suggestion_rate_limit.decay_seconds', 3600);

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $this->addError('suggestionEmail', __('Too many suggestions. Try again in :minutes minutes.', [
                'minutes' => max(1, (int) ceil($seconds / 60)),
            ]));

            return;
        }

        RoadmapSuggestion::query()->create([
            'title' => trim($validated['suggestionTitle']),
            'description' => trim($validated['suggestionDescription']),
            'email' => Str::lower(trim($validated['suggestionEmail'])),
            'name' => filled($validated['suggestionName'] ?? null)
                ? trim((string) $validated['suggestionName'])
                : null,
            'status' => RoadmapSuggestion::STATUS_NEW,
            'ip_address' => request()->ip(),
        ]);

        RateLimiter::hit($rateLimitKey, $decaySeconds);

        $this->reset(['suggestionName', 'suggestionEmail', 'suggestionTitle', 'suggestionDescription']);
        $this->suggestionSubmitted = true;
    }

    public function render(): View
    {
        $statuses = RoadmapItem::statusKeys();
        $itemsByStatus = [];

        foreach ($statuses as $status) {
            $itemsByStatus[$status] = RoadmapItem::query()
                ->published()
                ->status($status)
                ->area($this->area)
                ->releaseFilter($this->release)
                ->with(['targetRelease', 'shippedRelease'])
                ->ordered()
                ->get();
        }

        $recentlyShipped = RoadmapItem::query()
            ->recentlyShipped()
            ->when($this->area !== 'all', fn ($query) => $query->area($this->area))
            ->releaseFilter($this->release)
            ->with('shippedRelease')
            ->limit((int) config('roadmap.recently_shipped_limit', 5))
            ->get();

        $publishedReleases = RoadmapRelease::query()
            ->published()
            ->ordered()
            ->with(['shippedItems' => fn ($query) => $query->published()->area($this->area)->ordered()])
            ->get()
            ->filter(fn (RoadmapRelease $train): bool => $train->shippedItems->isNotEmpty());

        $activeRelease = $this->release !== 'all'
            ? RoadmapRelease::query()->published()->with(['shippedItems' => fn ($query) => $query->published()->area($this->area)->ordered()])->find($this->release)
            : null;

        return view('livewire.roadmap.index', [
            'itemsByStatus' => $itemsByStatus,
            'statusLabels' => config('roadmap.statuses', []),
            'areaLabels' => config('roadmap.areas', []),
            'publishedReleaseTrains' => RoadmapRelease::query()->published()->ordered()->get(),
            'releaseTimeline' => $publishedReleases,
            'activeRelease' => $activeRelease,
            'hasPublishedItems' => collect($itemsByStatus)->flatten(1)->isNotEmpty(),
            'recentlyShipped' => $recentlyShipped,
        ])->layout('layouts.status-public', [
            'title' => Str::of(__('Product roadmap'))->append(' – ', config('app.name'))->value(),
        ]);
    }

    private function suggestionRateLimitKey(string $email): string
    {
        return 'roadmap-suggestion:'.sha1(Str::lower(trim($email)).'|'.(request()->ip() ?? 'unknown'));
    }
}
