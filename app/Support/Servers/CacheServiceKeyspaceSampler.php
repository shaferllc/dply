<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Pure parsing helper for the keyspace dashboard. Takes a raw `INFO` buffer and
 * extracts a small fixed set of fields the dashboard plots, plus a delta-windowed
 * hit rate computed from the cumulative counters that INFO reports.
 *
 * No SSH calls — the caller is responsible for fetching the buffer (see
 * `CacheServiceStats::rawInfo()`).
 */
class CacheServiceKeyspaceSampler
{
    /**
     * @param  array<string, mixed>|null  $previous  The previous sample, used to compute window deltas.
     * @return array{
     *     ts: int,
     *     used_memory: int,
     *     used_memory_human: string,
     *     connected_clients: int,
     *     hits: int,
     *     misses: int,
     *     commands: int,
     *     ops_per_second_window: float|null,
     *     hit_rate_window: float|null,
     * }
     */
    public function sample(string $infoBuffer, ?array $previous = null, ?int $now = null): array
    {
        $parsed = $this->parse($infoBuffer);

        $ts = $now ?? time();
        $usedMemory = (int) ($parsed['used_memory'] ?? 0);
        $usedMemoryHuman = (string) ($parsed['used_memory_human'] ?? '');
        $clients = (int) ($parsed['connected_clients'] ?? 0);
        $hits = (int) ($parsed['keyspace_hits'] ?? 0);
        $misses = (int) ($parsed['keyspace_misses'] ?? 0);
        $commands = (int) ($parsed['total_commands_processed'] ?? 0);

        $opsWindow = null;
        $hitRateWindow = null;

        if (is_array($previous) && isset($previous['ts'], $previous['commands'], $previous['hits'], $previous['misses'])) {
            $dt = $ts - (int) $previous['ts'];
            if ($dt > 0) {
                $opsWindow = max(0.0, ($commands - (int) $previous['commands']) / $dt);
            }

            $dHits = $hits - (int) $previous['hits'];
            $dMisses = $misses - (int) $previous['misses'];
            $dTotal = $dHits + $dMisses;
            if ($dTotal > 0) {
                $hitRateWindow = $dHits / $dTotal;
            }
        }

        return [
            'ts' => $ts,
            'used_memory' => $usedMemory,
            'used_memory_human' => $usedMemoryHuman,
            'connected_clients' => $clients,
            'hits' => $hits,
            'misses' => $misses,
            'commands' => $commands,
            'ops_per_second_window' => $opsWindow,
            'hit_rate_window' => $hitRateWindow,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $raw): array
    {
        $parsed = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $kv = explode(':', $line, 2);
            if (count($kv) === 2) {
                $parsed[$kv[0]] = $kv[1];
            }
        }

        return $parsed;
    }
}
