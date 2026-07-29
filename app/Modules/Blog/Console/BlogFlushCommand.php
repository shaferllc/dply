<?php

declare(strict_types=1);

namespace App\Modules\Blog\Console;

use App\Modules\Blog\Services\BlogPosts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class BlogFlushCommand extends Command
{
    protected $signature = 'blog:flush';

    protected $description = 'Flush the cached blog manifest so new/edited posts show up.';

    public function handle(): int
    {
        Cache::forget(BlogPosts::CACHE_KEY);
        $this->info('Blog manifest cache flushed.');

        return self::SUCCESS;
    }
}
