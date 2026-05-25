<?php

namespace App\Livewire\Docs;

use App\Services\Docs\MarkdownDocRenderer;
use App\Support\Docs\ContextualDocResolver;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Sidebar extends Component
{
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

    public function mount(): void
    {
        $this->resetToIndex();
    }

    #[On('docs-sidebar-open')]
    public function open(?string $slug = null, ?string $docRoute = null, ?string $docSlug = null): void
    {
        $resolver = app(ContextualDocResolver::class);
        $this->slug = $resolver->resolve($slug, $docRoute, $docSlug);
        $this->loadSlug($resolver);
        $this->visible = true;
    }

    #[On('docs-sidebar-close')]
    public function handleClose(): void
    {
        $this->visible = false;
    }

    public function loadGuide(string $slug): void
    {
        $resolver = app(ContextualDocResolver::class);
        $this->slug = $slug;
        $this->loadSlug($resolver);
    }

    public function showIndex(): void
    {
        $this->resetToIndex();
    }

    public function close(): void
    {
        $this->visible = false;
    }

    public function render(): View
    {
        return view('livewire.docs.sidebar');
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
