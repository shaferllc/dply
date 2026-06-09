<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeAccessLog;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Maps Cloudflare Logpush http_requests records to Edge access logs.
 */
final class EdgeLogpushRecordImporter
{
    /** @var array<string, Site>|null */
    private ?array $hostnameIndex = null;

    public function __construct(
        private readonly EdgePerformanceHourlyRollup $rollup,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array{imported: int, skipped: int}
     */
    public function import(array $records): array
    {
        $imported = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                $skipped++;

                continue;
            }

            if ($this->importRecord($record)) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function importRecord(array $record): bool
    {
        $hostname = strtolower(trim((string) ($record['ClientRequestHost'] ?? '')));
        if ($hostname === '') {
            return false;
        }

        $site = $this->hostnameIndex()[$hostname] ?? null;
        if (! $site instanceof Site) {
            return false;
        }

        $status = (int) ($record['EdgeResponseStatus'] ?? 0);
        $bytes = max(0, (int) ($record['EdgeResponseBytes'] ?? 0));
        $durationMs = max(0, (int) ($record['EdgeTimeToFirstByteMs'] ?? 0));
        $occurredAt = $this->parseTimestamp($record['EdgeStartTimestamp'] ?? null);
        $path = Str::limit('/'.ltrim((string) ($record['ClientRequestURI'] ?? '/'), '/'), 2048, '');

        EdgeAccessLog::query()->create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'edge_deployment_id' => null,
            'hostname' => $hostname,
            'method' => strtoupper(Str::limit((string) ($record['ClientRequestMethod'] ?? 'GET'), 12, '')),
            'path' => $path,
            'status_code' => $status > 0 ? $status : null,
            'duration_ms' => $durationMs,
            'bytes_egress' => $bytes,
            'country' => Str::limit((string) ($record['ClientCountry'] ?? ''), 8, '') ?: null,
            'cache_status' => Str::limit((string) ($record['CacheCacheStatus'] ?? ''), 32, '') ?: null,
            'referrer' => Str::limit((string) ($record['ClientRequestReferer'] ?? ''), 2048, '') ?: null,
            'user_agent' => Str::limit((string) ($record['ClientRequestUserAgent'] ?? ''), 512, '') ?: null,
            'source' => 'logpush',
            'occurred_at' => $occurredAt,
        ]);

        $this->rollup->record(
            $site,
            $occurredAt,
            $status,
            $durationMs,
            $bytes,
            (string) ($record['CacheCacheStatus'] ?? ''),
            'logpush',
        );

        return true;
    }

    /**
     * @return array<string, Site>
     */
    private function hostnameIndex(): array
    {
        if ($this->hostnameIndex !== null) {
            return $this->hostnameIndex;
        }

        /** @var Collection<int, Site> $sites */
        $sites = Site::query()
            ->where('status', Site::STATUS_EDGE_ACTIVE)
            ->whereNotNull('edge_backend')
            ->where('edge_backend', '!=', '')
            ->get();

        $index = [];

        foreach ($sites as $site) {
            if ($site->isEdgePreview()) {
                continue;
            }

            foreach ($site->edgeUsageHostnames() as $hostname) {
                $index[strtolower($hostname)] = $site;
            }
        }

        return $this->hostnameIndex = $index;
    }

    private function parseTimestamp(mixed $value): Carbon
    {
        if (is_numeric($value)) {
            $seconds = (int) $value;
            if ($seconds > 1_000_000_000_000) {
                return Carbon::createFromTimestampMs($seconds);
            }

            return Carbon::createFromTimestamp($seconds);
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return now();
    }
}
