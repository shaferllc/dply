<?php

declare(strict_types=1);

namespace App\Modules\Blog;

use App\Modules\Blog\Console\BlogFlushCommand;
use App\Modules\Blog\Http\Controllers\BlogController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Public build-in-public devlog at /blog. Self-contained module: scans
 * content/blog/*.md, renders Markdown, and serves an index + per-post pages on
 * the public marketing shell (no auth).
 */
final class BlogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BlogFlushCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Route::middleware('web')->group(function (): void {
            Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
            Route::get('/blog/{slug}', [BlogController::class, 'show'])
                ->where('slug', '[a-z0-9-]+')
                ->name('blog.show');
        });
    }
}
