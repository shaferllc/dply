<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Pre-pull Node build images on Edge workers so the first deploy of the
 * day doesn't pay a cold `docker pull` (30–90s).
 */
class WarmEdgeBuildImagesCommand extends Command
{
    protected $signature = 'dply:edge:warm-build-images
                            {--image=* : Specific image(s); defaults to config edge.build.warm_images}';

    protected $description = 'Pre-pull Edge Docker build images (node:20 / node:22) on this worker';

    public function handle(): int
    {
        $images = $this->option('image');
        if (! is_array($images) || $images === []) {
            $images = config('edge.build.warm_images', ['node:20-bookworm', 'node:22-bookworm']);
        }
        $images = array_values(array_unique(array_filter(array_map(
            static fn ($image) => is_string($image) ? trim($image) : '',
            is_array($images) ? $images : [],
        ))));

        if ($images === []) {
            $this->warn('No images configured.');

            return self::SUCCESS;
        }

        $docker = Process::timeout(30)->run(['docker', 'info']);
        if (! $docker->successful()) {
            $this->error('Docker is not available on this host.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($images as $image) {
            $this->info("Pulling {$image}…");
            $pull = Process::timeout(900)->run(['docker', 'pull', $image]);
            if ($pull->successful()) {
                $this->line("  ok — {$image}");
            } else {
                $failed++;
                $this->error('  failed: '.trim($pull->errorOutput() ?: $pull->output()));
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
