<?php

namespace App\Http\Controllers;

use App\Services\Docs\MarkdownDocRenderer;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DocsController extends Controller
{
    public function __construct(
        private readonly MarkdownDocRenderer $markdownDocRenderer,
    ) {}

    public function index(): View
    {
        return view('docs.index');
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
     * Markdown pages registered in config/docs.php (`markdown` key).
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

        return view('docs.markdown-doc', [
            'title' => $rendered['title'],
            'html' => $rendered['html'],
        ]);
    }
}
