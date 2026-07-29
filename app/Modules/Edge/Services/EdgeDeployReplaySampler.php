<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeAccessLog;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Samples recent production traffic shapes for shadow replay against a preview.
 */
final class EdgeDeployReplaySampler
{
    private const ALLOWED_METHODS = ['GET', 'HEAD'];

    /**
     * @return list<array{method: string, path: string, prod_status: ?int, prod_duration_ms: int}>
     */
    public function sample(Site $productionSite, int $limit = 20, int $windowMinutes = 60): array
    {
        if ($productionSite->isEdgePreview()) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $windowMinutes = max(5, min(24 * 60, $windowMinutes));
        $since = now()->subMinutes($windowMinutes);

        /** @var Collection<int, EdgeAccessLog> $rows */
        $rows = EdgeAccessLog::query()
            ->where('site_id', $productionSite->id)
            ->where('occurred_at', '>=', $since)
            ->whereIn('method', self::ALLOWED_METHODS)
            ->orderByDesc('occurred_at')
            ->limit(500)
            ->get(['method', 'path', 'status_code', 'duration_ms']);

        $seen = [];
        $samples = [];

        foreach ($rows as $row) {
            $method = strtoupper((string) $row->method);
            $path = $this->normalizePath((string) $row->path);
            if ($path === '') {
                continue;
            }

            $key = $method.' '.$path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $samples[] = [
                'method' => $method,
                'path' => $path,
                'prod_status' => is_numeric($row->status_code) ? (int) $row->status_code : null,
                'prod_duration_ms' => (int) ($row->duration_ms ?? 0),
            ];

            if (count($samples) >= $limit) {
                break;
            }
        }

        return $samples;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }
}
