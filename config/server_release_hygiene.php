<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Release & disk hygiene
|--------------------------------------------------------------------------
|
| Thresholds for the VM release hygiene workspace and prune saved-command
| template shipped to the Run page.
|
*/

return [

    /** SSH scan older than this is flagged stale in the UI. */
    'stale_scan_hours' => max(1, (int) env('SERVER_RELEASE_HYGIENE_STALE_HOURS', 24)),

    'thresholds' => [
        'laravel_log_warning_mb' => max(1, (int) env('SERVER_RELEASE_HYGIENE_LOG_WARNING_MB', 25)),
        'laravel_log_critical_mb' => max(1, (int) env('SERVER_RELEASE_HYGIENE_LOG_CRITICAL_MB', 100)),
        'failed_jobs_warning' => max(1, (int) env('SERVER_RELEASE_HYGIENE_FAILED_JOBS_WARNING', 10)),
        'failed_jobs_critical' => max(1, (int) env('SERVER_RELEASE_HYGIENE_FAILED_JOBS_CRITICAL', 50)),
        'extra_releases_warning' => max(1, (int) env('SERVER_RELEASE_HYGIENE_EXTRA_RELEASES_WARNING', 1)),
        'extra_releases_critical' => max(1, (int) env('SERVER_RELEASE_HYGIENE_EXTRA_RELEASES_CRITICAL', 5)),
    ],

    'prune_saved_command' => [
        'name' => 'Prune atomic releases',
        'description' => 'Remove release folders beyond the newest N per site (default 5). Pass a different keep count as the first argument.',
        'script' => <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

# Dply release hygiene — prune old atomic release directories on this server.
# Keeps the newest N folders under each site's releases/ directory.
# Usage: run as-is (keep 5) or pass a keep count:  ./script 8

KEEP="${1:-5}"
if ! [[ "$KEEP" =~ ^[0-9]+$ ]] || [ "$KEEP" -lt 1 ]; then
  echo "Keep count must be a positive integer." >&2
  exit 1
fi

pruned=0
for releases_dir in /home/dply/*/releases /var/www/*/releases; do
  [ -d "$releases_dir" ] || continue
  cd "$releases_dir"
  while IFS= read -r folder; do
    [ -n "$folder" ] || continue
    [ -d "$folder" ] || continue
    rm -rf "$folder"
    pruned=$((pruned + 1))
    echo "Removed $releases_dir/$folder"
  done < <(ls -1t 2>/dev/null | tail -n +"$((KEEP + 1))")
done

echo "Pruned $pruned release folder(s); kept newest $KEEP per site."
BASH,
    ],

];
