<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DocsController extends Controller
{
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
        return $this->markdownFromDocsPath('HTTP_API.md', 'HTTP API');
    }

    /**
     * Markdown pages registered in config/docs.php (`markdown` key).
     */
    public function markdown(string $slug): View
    {
        $pages = config('docs.markdown', []);
        $page = $pages[$slug] ?? null;
        if (! is_array($page)) {
            throw new NotFoundHttpException;
        }

        $filename = $page['file'] ?? null;
        $title = $page['title'] ?? null;
        if (! is_string($filename) || $filename === '' || ! is_string($title) || $title === '') {
            throw new NotFoundHttpException;
        }

        return $this->markdownFromDocsPath($filename, $title);
    }

    private function markdownFromDocsPath(string $filename, string $title): View
    {
        $path = base_path('docs/'.$filename);
        if (! File::isFile($path)) {
            throw new NotFoundHttpException;
        }

        $html = Str::markdown(File::get($path));

        return view('docs.markdown-doc', [
            'title' => $title,
            'html' => $html,
        ]);
    }
}
