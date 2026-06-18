<?php

namespace App\Modules\Docs\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Modules\Docs\Services\DocsAskService;
use App\Modules\Docs\Services\MarkdownDocRenderer;
use App\Modules\Docs\Support\ContextualDocResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Sidebar extends Component
{
    use DispatchesToastNotifications;

    /** @var list<array{label: string, slug: string|null}> */
    public array $breadcrumbs = [];

    public bool $visible = false;

    public string $slug = 'docs-index';

    public string $title = '';

    public string $html = '';

    /** @var list<array{id: string, text: string, level: int}> */
    public array $headings = [];

    /** @var list<array{slug: string, title: string}> */
    public array $guideLinks = [];

    public string $guideGroupLabel = '';

    public ?string $fullPageUrl = null;

    public ?string $virtualSummary = null;

    public bool $isIndex = false;

    /** @var list<array{slug: string, title: string, url: string|null}> */
    public array $indexEntries = [];

    public string $askQuestion = '';

    public string $askAnswer = '';

    public string $askConfidence = '';

    /** @var list<string> */
    public array $askCitedHeadings = [];

    public ?string $askError = null;

    public bool $askLoading = false;

    public function mount(): void
    {
        // The panel is mounted persistently (hidden) and Alpine reveals it the
        // instant the user clicks "Documentation" — a tick BEFORE the
        // docs-sidebar-open event loads a slug. Without seeding a coherent
        // default here, that first paint renders empty derived state
        // ($slug='docs-index' but $isIndex=false, no html/summary), which falls
        // straight through to the "This guide is not available yet" fallback.
        // Initialise to the index so the first paint is the real index.
        $this->resetToIndex();
    }

    #[On('docs-sidebar-open')]
    public function open(?string $slug = null, ?string $docRoute = null, ?string $docSlug = null): void
    {
        $resolver = app(ContextualDocResolver::class);
        $this->slug = $resolver->resolve($slug, $docRoute, $docSlug);
        $this->resetAskState();
        $this->loadSlug($resolver);
        $this->visible = true;
    }

    #[On('docs-sidebar-close')]
    public function handleClose(): void
    {
        $this->visible = false;
        $this->resetAskState();
    }

    public function loadGuide(string $slug): void
    {
        $resolver = app(ContextualDocResolver::class);
        $this->slug = $slug;
        $this->resetAskState();
        $this->loadSlug($resolver);
    }

    public function showIndex(): void
    {
        $this->resetToIndex();
    }

    public function close(): void
    {
        $this->visible = false;
        $this->resetAskState();
    }

    public function submitDocsAsk(DocsAskService $docsAsk): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->askError = __('Sign in to an organization to use Docs Ask.');

            return;
        }

        $this->askLoading = true;
        $this->askError = null;
        $this->askAnswer = '';
        $this->askCitedHeadings = [];

        $routeName = Route::currentRouteName();
        $result = $docsAsk->ask(
            organization: $org,
            user: auth()->user(),
            slug: $this->slug,
            question: $this->askQuestion,
            routeName: is_string($routeName) ? $routeName : null,
        );

        $this->askLoading = false;

        if ($result['error'] !== null) {
            $this->askError = $result['error'];
            if ($result['answer'] === '') {
                return;
            }
        }

        $this->askAnswer = $result['answer'];
        $this->askConfidence = $result['confidence'];
        $this->askCitedHeadings = $result['cited_headings'];
    }

    public function render(): View
    {
        return view('livewire.docs.sidebar', [
            'docsAskEnabled' => ai_llm_active(auth()->user()?->currentOrganization())
                && (bool) config('dply_ai.features.docs_ask', true),
        ]);
    }

    private function resetAskState(): void
    {
        $this->askQuestion = '';
        $this->askAnswer = '';
        $this->askConfidence = '';
        $this->askCitedHeadings = [];
        $this->askError = null;
        $this->askLoading = false;
    }

    private function resetToIndex(): void
    {
        $resolver = app(ContextualDocResolver::class);
        $this->slug = 'docs-index';
        $this->title = $resolver->titleForSlug('docs-index') ?? __('Documentation');
        $this->html = '';
        $this->headings = [];
        $this->fullPageUrl = $resolver->fullPageUrlForSlug('docs-index');
        $this->virtualSummary = null;
        $this->isIndex = true;
        $this->indexEntries = $resolver->indexEntries();
        $this->guideLinks = [];
        $this->guideGroupLabel = '';
        $this->breadcrumbs = $resolver->breadcrumbsForSlug('docs-index');
        $this->resetAskState();
    }

    private function loadSlug(ContextualDocResolver $resolver): void
    {
        $this->isIndex = $this->slug === 'docs-index';
        $this->title = $resolver->titleForSlug($this->slug) ?? __('Documentation');
        $this->fullPageUrl = $resolver->fullPageUrlForSlug($this->slug);
        $this->virtualSummary = $resolver->virtualSummaryForSlug($this->slug);
        $this->indexEntries = [];
        $this->guideLinks = $this->buildGuideLinks($resolver);
        $this->guideGroupLabel = $resolver->guideGroup($this->slug)['label'] ?? '';
        $this->breadcrumbs = $resolver->breadcrumbsForSlug($this->slug);

        if ($this->isIndex) {
            $this->html = '';
            $this->headings = [];
            $this->indexEntries = $resolver->indexEntries();

            return;
        }

        if ($resolver->isVirtualOnlySlug($this->slug)) {
            $this->html = '';
            $this->headings = [];

            return;
        }

        if (! $resolver->isMarkdownSlug($this->slug)) {
            $this->html = '';
            $this->headings = [];

            return;
        }

        try {
            $rendered = app(MarkdownDocRenderer::class)->render($this->slug);
        } catch (NotFoundHttpException) {
            $this->html = '';
            $this->headings = [];

            return;
        }

        $this->html = $rendered['html'];
        $this->headings = $rendered['headings'];
    }

    /**
     * @return list<array{slug: string, title: string}>
     */
    private function buildGuideLinks(ContextualDocResolver $resolver): array
    {
        $group = $resolver->guideGroup($this->slug);
        if ($group === null) {
            return [];
        }

        $links = [];

        foreach ($group['slugs'] as $guideSlug) {
            $title = $resolver->titleForSlug($guideSlug);
            if ($title === null) {
                continue;
            }

            $links[] = [
                'slug' => $guideSlug,
                'title' => $title,
            ];
        }

        return $links;
    }
}
