<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\RoadmapRelease;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoadmapReleaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $trains = [
            ['slug' => '2026-05', 'summary' => 'BYO pipeline polish, deploy advisor, and workspace UX refinements.', 'is_published' => true, 'published_at' => '2026-05-15'],
            ['slug' => '2026-06', 'summary' => 'Edge, Cloud, and Serverless product lines move toward public beta.', 'is_published' => true, 'published_at' => '2026-06-01'],
            ['slug' => '2026-07', 'summary' => 'Server workspace depth: Console, Insights, Backups, and Files.', 'is_published' => true, 'published_at' => null],
            ['slug' => '2026-09', 'summary' => 'Marketplace runbooks, status pages, and managed servers preview.', 'is_published' => true, 'published_at' => null],
            ['slug' => '2026-12', 'summary' => 'Hosted WordPress and per-site CDN/caching rollout.', 'is_published' => false, 'published_at' => null],
        ];

        foreach ($trains as $index => $train) {
            RoadmapRelease::query()->updateOrCreate(
                ['slug' => $train['slug']],
                [
                    'title' => null,
                    'summary' => $train['summary'],
                    'published_at' => $train['published_at'],
                    'is_published' => $train['is_published'],
                    'sort_order' => $index,
                ],
            );
        }
    }
}
