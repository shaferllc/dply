<?php

namespace App\Modules\Docs\Http\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Docs\Services\DocsManifest;
use App\Modules\Docs\Services\DocsSearchIndex;
use App\Modules\Docs\Services\MarkdownDocRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DocsController extends Controller
{
    public function __construct(
        private readonly MarkdownDocRenderer $markdownDocRenderer,
        private readonly DocsManifest $manifest,
    ) {}

    public function index(): View
    {
        return view('docs.index', [
            'categories' => $this->manifest->byCategory(),
        ]);
    }

    /**
     * Client-side search index for the Cmd+K palette.
     */
    public function searchIndex(DocsSearchIndex $index): JsonResponse
    {
        return response()->json($index->cached());
    }

    public function connectProvider(): View
    {
        return view('docs.connect-provider');
    }

    public function createFirstServer(): View
    {
        return view('docs.create-first-server');
    }

    /**
     * Stable URL for HTTP API documentation (see docs/HTTP_API.md).
     */
    public function apiDocumentation(): View
    {
        return $this->markdownView('api');
    }

    /**
     * Markdown pages resolved via the front-matter manifest (config fallback).
     */
    public function markdown(string $slug): View
    {
        return $this->markdownView($slug);
    }

    private function markdownView(string $slug): View
    {
        try {
            $rendered = $this->markdownDocRenderer->render($slug);
        } catch (NotFoundHttpException) {
            throw new NotFoundHttpException;
        }

        $prevNext = $this->manifest->prevNext($slug);

        return view('docs.markdown-doc', [
            'slug' => $slug,
            'title' => $rendered['title'],
            'html' => $rendered['html'],
            'headings' => $rendered['headings'],
            'categories' => $this->manifest->byCategory(),
            'current' => $this->manifest->find($slug),
            'prev' => $prevNext['prev'],
            'next' => $prevNext['next'],
        ]);
    }
}
