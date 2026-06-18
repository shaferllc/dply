<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\EdgeDeployReplay;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Replays sampled production requests against a live preview URL.
 */
final class EdgeDeployReplayRunner
{
    /**
     * @param  array<string, mixed> $samples
     * @return array{results: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(Site $previewSite, array $samples): array
    {
        $baseUrl = rtrim((string) $previewSite->edgeLiveUrl(), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Preview has no live URL yet.');
        }

        $deployment = EdgeDeployment::query()
            ->where('site_id', $previewSite->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('published_at')
            ->first();

        if ($deployment === null || $deployment->storage_prefix === null) {
            throw new RuntimeException('Preview has no live deployment — redeploy the preview first.');
        }

        $results = [];
        $statusMatch = 0;
        $regressions = 0;
        $errors = 0;
        $previewDurations = [];

        foreach ($samples as $sample) {
            $method = strtoupper((string) ($sample['method'] ?? 'GET'));
            $path = (string) ($sample['path'] ?? '/');
            $url = $baseUrl.$path;
            $prodStatus = $sample['prod_status'] ?? null;

            $started = hrtime(true);
            try {
                $response = Http::timeout(20)
                    ->withHeaders([
                        'User-Agent' => 'dply-deploy-replay/1',
                        'Accept' => '*/*',
                    ])
                    ->send($method, $url);

                $previewStatus = $response->status();
                $previewMs = (int) round((hrtime(true) - $started) / 1_000_000);
                $previewDurations[] = $previewMs;

                $match = $this->statusesMatch($prodStatus, $previewStatus);
                if ($match) {
                    $statusMatch++;
                } elseif ($this->isRegression($prodStatus, $previewStatus)) {
                    $regressions++;
                }

                $results[] = [
                    'method' => $method,
                    'path' => $path,
                    'prod_status' => $prodStatus,
                    'preview_status' => $previewStatus,
                    'prod_duration_ms' => (int) ($sample['prod_duration_ms'] ?? 0),
                    'preview_duration_ms' => $previewMs,
                    'status_match' => $match,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                $errors++;
                $results[] = [
                    'method' => $method,
                    'path' => $path,
                    'prod_status' => $prodStatus,
                    'preview_status' => null,
                    'prod_duration_ms' => (int) ($sample['prod_duration_ms'] ?? 0),
                    'preview_duration_ms' => null,
                    'status_match' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $total = count($results);

        return [
            'results' => $results,
            'summary' => [
                'total' => $total,
                'status_match' => $statusMatch,
                'regressions' => $regressions,
                'errors' => $errors,
                'pass_rate' => $total > 0 ? round(($statusMatch / $total) * 100, 1) : 0.0,
                'avg_preview_duration_ms' => $previewDurations !== []
                    ? (int) round(array_sum($previewDurations) / count($previewDurations))
                    : null,
            ],
        ];
    }

    public function execute(EdgeDeployReplay $replay): EdgeDeployReplay
    {
        $replay->update([
            'status' => EdgeDeployReplay::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $preview = Site::query()->findOrFail($replay->preview_site_id);
        $samples = ($replay->samples );

        if ($samples === []) {
            $replay->update([
                'status' => EdgeDeployReplay::STATUS_FAILED,
                'error_message' => 'No production traffic samples found in the selected window.',
                'finished_at' => now(),
            ]);

            return $replay->fresh();
        }

        try {
            $payload = $this->run($preview, $samples);
            $deployment = EdgeDeployment::query()
                ->where('site_id', $preview->id)
                ->where('status', EdgeDeployment::STATUS_LIVE)
                ->latest('published_at')
                ->first();

            $replay->update([
                'status' => EdgeDeployReplay::STATUS_COMPLETED,
                'preview_deployment_id' => $deployment?->id,
                'results' => $payload['results'],
                'summary' => $payload['summary'],
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $replay->update([
                'status' => EdgeDeployReplay::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }

        return $replay->fresh();
    }

    private function statusesMatch(?int $prod, int $preview): bool
    {
        if ($prod === null) {
            return $preview >= 200 && $preview < 400;
        }

        if ($prod === $preview) {
            return true;
        }

        $prodClass = intdiv($prod, 100);
        $previewClass = intdiv($preview, 100);

        return $prodClass === $previewClass;
    }

    private function isRegression(?int $prod, int $preview): bool
    {
        if ($prod === null) {
            return $preview >= 500;
        }

        if ($prod < 400 && $preview >= 400) {
            return true;
        }

        if ($prod < 500 && $preview >= 500) {
            return true;
        }

        return false;
    }
}
