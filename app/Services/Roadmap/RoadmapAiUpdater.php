<?php

declare(strict_types=1);

namespace App\Services\Roadmap;

use App\Models\RoadmapAiRun;
use App\Models\RoadmapItem;
use App\Models\RoadmapRelease;
use App\Models\RoadmapSuggestion;
use App\Services\Ai\AiPromptBuilder;
use App\Services\Ai\LlmSynthesizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Post-deploy AI maintenance of the public roadmap.
 *
 * Gathers four inputs — recent git commits (what shipped), the open suggestion
 * inbox, the docs/*roadmap*.md files, and the existing roadmap items — asks the
 * configured LLM for a structured plan, and applies it: flip items to shipped,
 * draft genuinely-new items, triage suggestions, refresh summaries, and write a
 * release summary.
 *
 * Gated OFF by default (config roadmap.ai.enabled) and additionally a no-op when
 * the LLM isn't configured. Every run — including skips and failures — records a
 * {@see RoadmapAiRun} row, whose `to_commit` is the cursor the next run advances
 * from. Applying the plan is wrapped in a transaction so a malformed item can't
 * leave the board half-updated.
 */
final class RoadmapAiUpdater
{
    public function __construct(
        private readonly RoadmapGitHistory $git,
        private readonly AiPromptBuilder $prompts,
        private readonly LlmSynthesizer $llm,
    ) {}

    public function run(?string $deployedCommit = null): RoadmapAiRun
    {
        if (! (bool) config('roadmap.ai.enabled', false)) {
            return $this->record(RoadmapAiRun::STATUS_SKIPPED, note: 'roadmap.ai.enabled is false');
        }

        if (! $this->llm->isConfigured()) {
            return $this->record(RoadmapAiRun::STATUS_SKIPPED, note: 'LLM is not configured');
        }

        $from = RoadmapAiRun::latestCompletedToCommit();
        $to = $deployedCommit ?: $this->git->headCommit();
        $commits = $this->git->commitsSince($from, $to);

        $openSuggestions = RoadmapSuggestion::query()
            ->where('status', RoadmapSuggestion::STATUS_NEW)
            ->latest('created_at')
            ->limit(40)
            ->get();

        if ($commits === [] && $openSuggestions->isEmpty()) {
            return $this->record(
                RoadmapAiRun::STATUS_SKIPPED,
                note: 'No new commits or open suggestions since last run',
                from: $from,
                to: $to,
            );
        }

        $context = $this->buildContext($commits, $openSuggestions);

        try {
            $response = $this->llm->completeJson(
                $this->prompts->roadmapUpdateSystem($context),
                'You maintain a software product roadmap. Respond with valid JSON only.',
            );
        } catch (\Throwable $e) {
            Log::warning('RoadmapAiUpdater: LLM call failed', ['error' => $e->getMessage()]);

            return $this->record(
                RoadmapAiRun::STATUS_FAILED,
                note: 'LLM call failed: '.$e->getMessage(),
                from: $from,
                to: $to,
                commits: count($commits),
            );
        }

        $plan = ($response['data'] );

        try {
            $counts = DB::transaction(fn (): array => $this->applyPlan($plan));
        } catch (\Throwable $e) {
            Log::error('RoadmapAiUpdater: applying plan failed', ['error' => $e->getMessage()]);

            return $this->record(
                RoadmapAiRun::STATUS_FAILED,
                note: 'Apply failed: '.$e->getMessage(),
                from: $from,
                to: $to,
                commits: count($commits),
            );
        }

        return $this->record(
            RoadmapAiRun::STATUS_COMPLETED,
            from: $from,
            to: $to,
            commits: count($commits),
            counts: $counts,
            tokens: [$response['prompt_tokens'] ?? null, $response['completion_tokens'] ?? null, $response['latency_ms']],
            plan: $plan,
        );
    }

    /**
     * @param  array<string, mixed> $commits
     * @param  Collection<int, RoadmapSuggestion>  $suggestions
     * @return array<string, mixed>
     */
    private function buildContext(array $commits, $suggestions): array
    {
        $items = RoadmapItem::query()
            ->ordered()
            ->limit(300)
            ->get(['id', 'title', 'summary', 'status', 'area', 'target_quarter'])
            ->map(fn (RoadmapItem $i): array => [
                'id' => (string) $i->id,
                'title' => (string) $i->title,
                'summary' => (string) ($i->summary ?? ''),
                'status' => (string) $i->status,
                'area' => (string) ($i->area ?? ''),
            ])->all();

        return [
            'today' => Carbon::now()->toDateString(),
            'allowed' => [
                'areas' => RoadmapItem::areaKeys(),
                'statuses' => RoadmapItem::statusKeys(),
            ],
            'limits' => [
                'max_new_items' => (int) config('roadmap.ai.max_new_items', 8),
            ],
            'commits' => array_map(fn (array $c): array => [
                'subject' => $c['subject'],
                'body' => Str::limit($c['body'], 400, ''),
                'date' => $c['date'],
            ], $commits),
            'open_suggestions' => $suggestions->map(fn (RoadmapSuggestion $s): array => [
                'id' => (string) $s->id,
                'title' => (string) $s->title,
                'description' => Str::limit((string) $s->description, 600, ''),
            ])->all(),
            'existing_items' => $items,
            'roadmap_docs' => $this->roadmapDocs(),
        ];
    }

    /** Concatenated docs/*roadmap*.md content, truncated to the configured cap. */
    private function roadmapDocs(): string
    {
        $dir = base_path('docs');
        if (! is_dir($dir)) {
            return '';
        }

        $buffer = '';
        foreach (File::glob($dir.'/*.md') as $path) {
            if (! str_contains(Str::lower(basename($path)), 'roadmap')) {
                continue;
            }
            $buffer .= "\n\n# ".basename($path)."\n".File::get($path);
        }

        return Str::limit(trim($buffer), (int) config('roadmap.ai.max_doc_chars', 12000), '');
    }

    /**
     * Apply the model's plan. Returns per-action counts.
     *
     * @param  array<string, mixed> $plan
     * @return array{shipped: int, created: int, triaged: int, summaries: int}
     */
    private function applyPlan(array $plan): array
    {
        $autoPublish = (bool) config('roadmap.ai.auto_publish', true);
        $today = Carbon::now()->startOfDay();

        $shipped = $this->applyShip($plan['ship'] ?? [], $autoPublish, $today);
        $created = $this->applyNewItems($plan['new_items'] ?? [], $autoPublish, $today);
        $triaged = $this->applyTriage($plan['suggestions'] ?? []);
        $summaries = $this->applyItemSummaries($plan['item_summaries'] ?? []);
        $summaries += $this->applyReleaseSummary($plan['release'] ?? null, $autoPublish, $today);

        return ['shipped' => $shipped, 'created' => $created, 'triaged' => $triaged, 'summaries' => $summaries];
    }

    /** @param  mixed  $rows */
    private function applyShip($rows, bool $autoPublish, Carbon $today): int
    {
        if (! is_array($rows)) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $item = $this->locateItem($row['item_id'] ?? null, $row['title'] ?? null);
            if (! $item) {
                continue;
            }

            $item->status = RoadmapItem::STATUS_SHIPPED;
            if ($item->shipped_at === null) {
                $item->shipped_at = $today;
            }
            if ($autoPublish) {
                $item->is_published = true;
            }
            if ($release = $this->resolveRelease($row['release_slug'] ?? null, $autoPublish, $today)) {
                $item->shipped_release_id = $release->id;
            }
            $item->save();
            $count++;
        }

        return $count;
    }

    /** @param  mixed  $rows */
    private function applyNewItems($rows, bool $autoPublish, Carbon $today): int
    {
        if (! is_array($rows)) {
            return 0;
        }

        $cap = max(0, (int) config('roadmap.ai.max_new_items', 8));
        $seen = $this->existingTitleSet();
        $statuses = RoadmapItem::statusKeys();
        $areas = RoadmapItem::areaKeys();

        $count = 0;
        foreach ($rows as $row) {
            if ($count >= $cap || ! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $key = $this->normalizeTitle($title);
            if ($title === '' || isset($seen[$key])) {
                continue;
            }

            $status = (string) ($row['status'] ?? RoadmapItem::STATUS_PLANNED);
            if (! in_array($status, $statuses, true)) {
                $status = RoadmapItem::STATUS_PLANNED;
            }
            $area = (string) ($row['area'] ?? '');
            if (! in_array($area, $areas, true)) {
                $area = 'other';
            }

            $item = new RoadmapItem([
                'title' => Str::limit($title, 200, ''),
                'summary' => Str::limit(trim((string) ($row['summary'] ?? '')), 200, ''),
                'description' => Str::limit(trim((string) ($row['description'] ?? '')), 1500, ''),
                'status' => $status,
                'area' => $area,
                'is_published' => $autoPublish,
            ]);

            if ($status === RoadmapItem::STATUS_SHIPPED) {
                $item->shipped_at = $today;
                if ($release = $this->resolveRelease($row['release_slug'] ?? null, $autoPublish, $today)) {
                    $item->shipped_release_id = $release->id;
                }
            } elseif ($release = $this->resolveRelease($row['release_slug'] ?? null, $autoPublish, $today)) {
                $item->target_release_id = $release->id;
            }

            $item->save();
            $seen[$key] = true;
            $count++;
        }

        return $count;
    }

    /** @param  mixed  $rows */
    private function applyTriage($rows): int
    {
        if (! is_array($rows)) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $decision = (string) ($row['decision'] ?? '');
            if ($id === '' || ! in_array($decision, [RoadmapSuggestion::STATUS_REVIEWED, RoadmapSuggestion::STATUS_DECLINED], true)) {
                continue;
            }

            // Only ever act on still-open suggestions — never re-triage one a
            // human already handled.
            $suggestion = RoadmapSuggestion::query()
                ->whereKey($id)
                ->where('status', RoadmapSuggestion::STATUS_NEW)
                ->first();
            if (! $suggestion) {
                continue;
            }

            $note = Str::limit(trim((string) ($row['admin_notes'] ?? '')), 1000, '');
            $suggestion->status = $decision;
            $suggestion->admin_notes = $note !== '' ? '[AI] '.$note : '[AI] Triaged automatically on deploy.';
            $suggestion->save();
            $count++;
        }

        return $count;
    }

    /** @param  mixed  $rows */
    private function applyItemSummaries($rows): int
    {
        if (! is_array($rows)) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $summary = Str::limit(trim((string) ($row['summary'] ?? '')), 200, '');
            $item = $this->locateItem($row['item_id'] ?? null, null);
            if (! $item || $summary === '' || (string) $item->summary === $summary) {
                continue;
            }
            $item->summary = $summary;
            $item->save();
            $count++;
        }

        return $count;
    }

    /** @param  mixed  $release */
    private function applyReleaseSummary($release, bool $autoPublish, Carbon $today): int
    {
        if (! is_array($release)) {
            return 0;
        }
        $model = $this->resolveRelease($release['slug'] ?? null, $autoPublish, $today);
        if (! $model) {
            return 0;
        }

        $summary = Str::limit(trim((string) ($release['summary'] ?? '')), 5000, '');
        $title = Str::limit(trim((string) ($release['title'] ?? '')), 200, '');
        if ($summary === '' && $title === '') {
            return 0;
        }

        if ($summary !== '') {
            $model->summary = $summary;
        }
        if ($title !== '') {
            $model->title = $title;
        }
        if ($autoPublish) {
            $model->is_published = true;
            $model->published_at ??= $today;
        }
        $model->save();

        return 1;
    }

    private function locateItem(mixed $id, mixed $title): ?RoadmapItem
    {
        $id = is_string($id) ? trim($id) : '';
        if ($id !== '') {
            $item = RoadmapItem::query()->whereKey($id)->first();
            if ($item) {
                return $item;
            }
        }
        $title = is_string($title) ? trim($title) : '';
        if ($title === '') {
            return null;
        }

        return RoadmapItem::query()->whereRaw('LOWER(title) = ?', [Str::lower($title)])->first();
    }

    private function resolveRelease(mixed $slug, bool $autoPublish, Carbon $today): ?RoadmapRelease
    {
        $slug = is_string($slug) ? trim($slug) : '';
        if (preg_match('/^\d{4}-\d{2}$/', $slug) !== 1) {
            return null;
        }

        return RoadmapRelease::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'is_published' => $autoPublish,
                'published_at' => $autoPublish ? $today : null,
                'sort_order' => 0,
            ],
        );
    }

    /** @return array<string, true> */
    private function existingTitleSet(): array
    {
        $set = [];
        foreach (RoadmapItem::query()->pluck('title') as $title) {
            $set[$this->normalizeTitle((string) $title)] = true;
        }

        return $set;
    }

    private function normalizeTitle(string $title): string
    {
        return Str::of($title)->lower()->squish()->value();
    }

    /**
     * @param  array{0: int|null, 1: int|null, 2: int|null}  $tokens
     * @param  array{shipped: int, created: int, triaged: int, summaries: int}|null  $counts
     * @param  array<string, mixed>|null  $plan
     */
    private function record(
        string $status,
        ?string $note = null,
        ?string $from = null,
        ?string $to = null,
        int $commits = 0,
        ?array $counts = null,
        array $tokens = [null, null, null],
        ?array $plan = null,
    ): RoadmapAiRun {
        return RoadmapAiRun::create([
            'status' => $status,
            'from_commit' => $from,
            'to_commit' => $to,
            'commits_considered' => $commits,
            'items_shipped' => $counts['shipped'] ?? 0,
            'items_created' => $counts['created'] ?? 0,
            'suggestions_triaged' => $counts['triaged'] ?? 0,
            'summaries_updated' => $counts['summaries'] ?? 0,
            'prompt_tokens' => $tokens[0] ?? null,
            'completion_tokens' => $tokens[1] ?? null,
            'latency_ms' => $tokens[2] ?? null,
            'note' => $note,
            'plan' => $plan,
        ]);
    }
}
