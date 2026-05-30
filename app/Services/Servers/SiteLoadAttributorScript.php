<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * Builds and parses the SSH site-load attribution scan for multi-site VM hosts.
 */
final class SiteLoadAttributorScript
{
    /**
     * @param  list<array{slug: string, path: string}>  $sites
     */
    public function build(array $sites): string
    {
        $blocks = [
            'printf "SCAN_BEGIN\n"',
        ];

        foreach ($sites as $site) {
            $slug = $this->shellLiteral((string) $site['slug']);
            $path = $this->shellLiteral(rtrim((string) $site['path'], '/'));

            $blocks[] = <<<SH
printf "SITE_BEGIN slug={$slug}\n"
path={$path}
mem_kb=0
cpu_pct=0
if command -v ps >/dev/null 2>&1; then
  mem_kb=\$(ps -eo rss=,args= 2>/dev/null | awk -v p={$path} 'index(\$0, p) > 0 { s += \$1 } END { printf "%.0f", s + 0 }')
  cpu_pct=\$(ps -eo pcpu=,args= 2>/dev/null | awk -v p={$path} 'index(\$0, p) > 0 { s += \$1 } END { printf "%.1f", s + 0 }')
fi
printf "mem_kb=%s\ncpu_pct=%s\n" "\$mem_kb" "\$cpu_pct"
printf "SITE_END\n"
SH;
        }

        $blocks[] = <<<'SH'
total_mem_kb=0
total_cpu=0
if command -v ps >/dev/null 2>&1; then
  total_mem_kb=$(ps -eo rss= 2>/dev/null | awk '{ s += $1 } END { printf "%.0f", s + 0 }')
  total_cpu=$(ps -eo pcpu= 2>/dev/null | awk '{ s += $1 } END { printf "%.1f", s + 0 }')
fi
printf "TOTAL_BEGIN\n"
printf "mem_kb=%s\ncpu_pct=%s\n" "$total_mem_kb" "$total_cpu"
printf "TOTAL_END\n"
printf "SCAN_END\n"
SH;

        return implode("\n", $blocks);
    }

    /**
     * @return array{
     *     checked_at: string,
     *     sites: list<array{slug: string, mem_kb: int, cpu_pct: float, mem_mb: float}>,
     *     total: array{mem_kb: int, cpu_pct: float, mem_mb: float},
     *     unattributed: array{mem_kb: int, cpu_pct: float, mem_mb: float},
     * }
     */
    public function parse(string $output): array
    {
        $sites = [];
        $total = ['mem_kb' => 0, 'cpu_pct' => 0.0, 'mem_mb' => 0.0];
        $current = null;

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'SITE_BEGIN slug=')) {
                $current = [
                    'slug' => substr($line, strlen('SITE_BEGIN slug=')),
                    'mem_kb' => 0,
                    'cpu_pct' => 0.0,
                ];

                continue;
            }
            if ($line === 'SITE_END') {
                if (is_array($current)) {
                    $current['mem_mb'] = round(((int) $current['mem_kb']) / 1024, 1);
                    $sites[] = $current;
                }
                $current = null;

                continue;
            }
            if ($line === 'TOTAL_BEGIN') {
                continue;
            }
            if ($line === 'TOTAL_END' || $line === 'SCAN_END') {
                continue;
            }
            if (str_starts_with($line, 'mem_kb=') && $current === null && $total['mem_kb'] === 0 && ! str_contains($output, 'SITE_BEGIN')) {
                // noop guard
            }
            if (is_array($current)) {
                if (str_starts_with($line, 'mem_kb=')) {
                    $current['mem_kb'] = (int) substr($line, strlen('mem_kb='));
                } elseif (str_starts_with($line, 'cpu_pct=')) {
                    $current['cpu_pct'] = (float) substr($line, strlen('cpu_pct='));
                }

                continue;
            }
            if (str_starts_with($line, 'mem_kb=') && $current === null) {
                $total['mem_kb'] = (int) substr($line, strlen('mem_kb='));
            } elseif (str_starts_with($line, 'cpu_pct=') && $current === null) {
                $total['cpu_pct'] = (float) substr($line, strlen('cpu_pct='));
            }
        }

        $total['mem_mb'] = round($total['mem_kb'] / 1024, 1);

        $attributedMem = array_sum(array_column($sites, 'mem_kb'));
        $attributedCpu = array_sum(array_map(static fn (array $row): float => (float) ($row['cpu_pct'] ?? 0), $sites));

        $unattributedMem = max(0, $total['mem_kb'] - $attributedMem);
        $unattributedCpu = max(0.0, $total['cpu_pct'] - $attributedCpu);

        return [
            'checked_at' => now()->toIso8601String(),
            'sites' => $sites,
            'total' => $total,
            'unattributed' => [
                'mem_kb' => $unattributedMem,
                'cpu_pct' => round($unattributedCpu, 1),
                'mem_mb' => round($unattributedMem / 1024, 1),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $existingMeta
     * @return array<string, mixed>
     */
    public function mergeIntoMeta(array $snapshot, array $existingMeta = []): array
    {
        $meta = $existingMeta;
        $meta[(string) config('server_shared_host.attribution.meta_key', 'shared_host_attribution_snapshot')] = $snapshot;

        return $meta;
    }

    private function shellLiteral(string $value): string
    {
        return escapeshellarg($value);
    }
}
