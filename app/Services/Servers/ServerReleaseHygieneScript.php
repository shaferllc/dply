<?php

declare(strict_types=1);

namespace App\Services\Servers;

/**
 * Builds and parses the SSH release-hygiene scan for VM sites on a server.
 */
final class ServerReleaseHygieneScript
{
    /**
     * @param  list<array{slug: string, path: string, keep: int, atomic: bool}>  $sites
     */
    public function build(array $sites): string
    {
        $blocks = [
            'printf "SCAN_BEGIN\n"',
        ];

        foreach ($sites as $site) {
            $slug = $this->shellLiteral((string) $site['slug']);
            $path = $this->shellLiteral(rtrim((string) $site['path'], '/'));
            $keep = max(1, min(50, (int) $site['keep']));
            $atomic = ($site['atomic'] ?? false) ? '1' : '0';

            $blocks[] = <<<SH
printf "SITE_BEGIN slug={$slug}\n"
base={$path}
keep={$keep}
atomic={$atomic}
release_count=0
extra=0
release_bytes=0
laravel_log_bytes=0
failed_jobs=
if [ "\$atomic" = "1" ] && [ -d "\$base/releases" ]; then
  release_count=\$(ls -1 "\$base/releases" 2>/dev/null | wc -l | tr -d " ")
  if [ "\$release_count" -gt "\$keep" ]; then
    extra=\$((release_count - keep))
  fi
  release_bytes=\$(du -sb "\$base/releases" 2>/dev/null | awk '{print \$1}')
fi
log_path="\$base/current/storage/logs/laravel.log"
if [ ! -f "\$log_path" ] && [ -f "\$base/shared/storage/logs/laravel.log" ]; then
  log_path="\$base/shared/storage/logs/laravel.log"
fi
if [ -f "\$log_path" ]; then
  laravel_log_bytes=\$(stat -c%s "\$log_path" 2>/dev/null || wc -c < "\$log_path" 2>/dev/null || echo 0)
fi
if [ -f "\$base/current/artisan" ]; then
  failed_jobs=\$(cd "\$base/current" && php artisan queue:failed 2>/dev/null | tail -n +2 | grep -c . || true)
  failed_jobs=\${failed_jobs:-0}
fi
if [ -f "\$log_path" ]; then
  printf "laravel_log_path=%s\n" "\$log_path"
fi
printf "release_count=%s\nextra=%s\nrelease_bytes=%s\nlaravel_log_bytes=%s\nfailed_jobs=%s\n" "\$release_count" "\$extra" "\$release_bytes" "\$laravel_log_bytes" "\$failed_jobs"
printf "SITE_END\n"
SH;
        }

        $blocks[] = 'printf "SYS_BEGIN\n"';
        $blocks[] = <<<'SH'
journal_line="$(journalctl --disk-usage 2>/dev/null | head -n 1 || true)"
printf "journal_usage=%s\n" "$journal_line"
for logfile in /var/log/nginx/access.log /var/log/nginx/error.log /var/log/syslog; do
  if [ -f "$logfile" ]; then
    bytes=$(stat -c%s "$logfile" 2>/dev/null || wc -c < "$logfile" 2>/dev/null || echo 0)
    printf "logfile path=%s bytes=%s\n" "$logfile" "$bytes"
  fi
done
printf "SYS_END\n"
printf "SCAN_END\n"
SH;

        return implode("\n", $blocks);
    }

    /**
     * @return array{
     *     checked_at: string,
     *     sites: list<array<string, mixed>>,
     *     system: array<string, mixed>,
     * }
     */
    public function parse(string $output, array $existingMeta = []): array
    {
        $sites = [];
        $system = [
            'journal_usage' => null,
            'logfiles' => [],
        ];

        $current = null;
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === 'SITE_BEGIN') {
                continue;
            }
            if (str_starts_with($line, 'SITE_BEGIN slug=')) {
                $current = [
                    'slug' => substr($line, strlen('SITE_BEGIN slug=')),
                    'release_count' => 0,
                    'extra' => 0,
                    'release_bytes' => 0,
                    'laravel_log_bytes' => 0,
                    'laravel_log_path' => null,
                    'failed_jobs' => null,
                ];

                continue;
            }
            if ($line === 'SITE_END') {
                if (is_array($current)) {
                    $sites[] = $current;
                }
                $current = null;

                continue;
            }
            if ($current !== null && str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                if ($key === 'failed_jobs' && $value === '') {
                    $current['failed_jobs'] = null;
                } elseif (in_array($key, ['release_count', 'extra', 'release_bytes', 'laravel_log_bytes'], true)) {
                    $current[$key] = max(0, (int) $value);
                } elseif ($key === 'laravel_log_path' && $value !== '') {
                    $current['laravel_log_path'] = $value;
                } elseif ($key === 'failed_jobs') {
                    $current['failed_jobs'] = max(0, (int) $value);
                }
            }

            if ($line === 'SYS_BEGIN' || $line === 'SYS_END' || $line === 'SCAN_BEGIN' || $line === 'SCAN_END') {
                continue;
            }
            if (str_starts_with($line, 'journal_usage=')) {
                $usage = trim(substr($line, strlen('journal_usage=')));
                $system['journal_usage'] = $usage !== '' ? $usage : null;
            }
            if (str_starts_with($line, 'logfile path=')) {
                if (preg_match('/^logfile path=(.+?) bytes=(\d+)$/', $line, $matches)) {
                    $system['logfiles'][] = [
                        'path' => $matches[1],
                        'bytes' => (int) $matches[2],
                    ];
                }
            }
        }

        $meta = is_array($existingMeta) ? $existingMeta : [];
        $meta['release_hygiene_snapshot'] = [
            'checked_at' => now()->toIso8601String(),
            'sites' => $sites,
            'system' => $system,
        ];

        return $meta;
    }

    private function shellLiteral(string $value): string
    {
        return escapeshellarg($value);
    }
}
