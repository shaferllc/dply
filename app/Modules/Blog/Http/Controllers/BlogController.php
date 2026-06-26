<?php

declare(strict_types=1);

namespace App\Modules\Blog\Http\Controllers;

use App\Modules\Blog\Services\BlogPosts;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public build-in-public devlog. Plain controller (no shell base class) so the
 * Blog module stays free of a dependency on app/Http/Controllers.
 */
final class BlogController
{
    public function index(BlogPosts $posts): View
    {
        return view('blog.index', [
            'posts' => $posts->all(),
        ]);
    }

    public function show(string $slug, BlogPosts $posts): View
    {
        $post = $posts->find($slug);
        if ($post === null) {
            throw new NotFoundHttpException;
        }

        return view('blog.show', [
            'post' => $post,
            'html' => $posts->renderHtml($post),
            'recent' => $posts->all()->where('slug', '!=', $slug)->take(5),
        ]);
    }
}
