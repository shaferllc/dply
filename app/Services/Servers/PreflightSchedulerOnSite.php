<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

/**
 * SSH preflight bundle for "Enable scheduler" (Q18).
 *
 * Runs all seven checks in a single SSH round-trip so the operator's wait is
 * a single ~2-3s blob instead of seven sequential probes. Each check emits
 * one stamped line into stdout:
 *
 *     DPLY_PREFLIGHT: <key> <status> <message...>
 *
 * Status values: `pass` (silent green) | `warn` (allowed, surfaced) |
 * `fail` (block hard). The wrapper splits structural failures from advisory
 * warnings per Q18 (j) — only structural fails block Enable; advisory warns
 * surface alongside the result but don't refuse the action.
 *
 * The parser is the testable surface; SSH wiring is in {@see run()}.
 */
class PreflightSchedulerOnSite
{
    public const STATUS_PASS = 'pass';

    public const STATUS_WARN = 'warn';

    public const STATUS_FAIL = 'fail';

    /** Check keys → severity. `fail` blocks Enable; `warn` allows it. */
    public const STRUCTURAL_CHECKS = [
        'site_release_present',
        'php_binary',
        'artisan_file',
        'laravel_boots',
        'cron_user_access',
    ];

    public const ADVISORY_CHECKS = [
        'scheduler_has_tasks',
        'no_duplicate_scheduler',
    ];

    public function __construct(
        private ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * Build the bash bundle that runs every check and stamps results.
     *
     * Returns a single bash heredoc the SSH layer can ship. Per-check
     * commands are short and silent — the only relevant output is the
     * stamped lines. Anything else (stdout / stderr) is captured but
     * irrelevant to the parser.
     */
    public function buildScript(Site $site): string
    {
        $repoPath = rtrim($site->effectiveRepositoryPath(), '/');
        $currentDir = $repoPath.'/current';
        $deployUser = $site->effectiveSystemUser($site->server) ?: 'dply';

        $repo = escapeshellarg($currentDir);
        $user = escapeshellarg($deployUser);

        // PHP binary detection — `which php` is the universal probe; we
        // accept any `php` on PATH. Operators with multiple PHP versions can
        // get more specific via the site's php_fpm_user, but for preflight
        // any callable `php` is enough.
        return <<<BASH
set +e
emit() { printf 'DPLY_PREFLIGHT: %s %s %s\\n' "\$1" "\$2" "\$3"; }

# 1. site release present
if [ -d {$repo} ]; then
    emit site_release_present pass "current release found at {$currentDir}"
else
    emit site_release_present fail "no current release at {$currentDir} — deploy the site first"
fi

# 2. php binary
if PHP_PATH=\$(command -v php 2>/dev/null) && [ -n "\$PHP_PATH" ]; then
    emit php_binary pass "php available at \$PHP_PATH"
else
    emit php_binary fail "php binary not found on PATH — install PHP for this site"
fi

# 3. artisan file
if [ -f {$repo}/artisan ]; then
    emit artisan_file pass "artisan file present"
else
    emit artisan_file fail "no artisan file at {$repo}/artisan — not a Laravel app?"
fi

# 4. laravel boots (schedule:list succeeds)
SCHEDULE_OUT=\$(cd {$repo} 2>/dev/null && php artisan schedule:list 2>&1)
SCHEDULE_EXIT=\$?
if [ \$SCHEDULE_EXIT -eq 0 ]; then
    emit laravel_boots pass "schedule:list ran successfully"
    # 5. task count (advisory) — count non-empty non-header lines as a proxy.
    TASK_COUNT=\$(printf '%s\\n' "\$SCHEDULE_OUT" | grep -cE '^\\s+\\* ' 2>/dev/null || true)
    if [ "\$TASK_COUNT" -gt 0 ] 2>/dev/null; then
        emit scheduler_has_tasks pass "found \$TASK_COUNT scheduled task(s)"
    else
        emit scheduler_has_tasks warn "schedule:list returned no tasks — Enable will create a scheduler that has nothing to do until you register tasks"
    fi
else
    emit laravel_boots fail "schedule:list exited \$SCHEDULE_EXIT — app may be broken (check .env, deps, migrations)"
fi

# 6. cron access for the deploy user
if sudo -nu {$user} crontab -l >/dev/null 2>&1; then
    emit cron_user_access pass "user {$deployUser} can read crontab"
else
    # crontab -l exits 1 when the user has no crontab — that's fine; we only
    # care if access is genuinely denied. Try writing a dry probe.
    if sudo -nu {$user} sh -c 'echo "" | crontab - && crontab -l' >/dev/null 2>&1; then
        emit cron_user_access pass "user {$deployUser} can write crontab"
    else
        emit cron_user_access fail "user {$deployUser} cannot access crontab — check sudo policy"
    fi
fi

# 7. no duplicate scheduler under another user (advisory)
DUP=\$(sudo -n bash -c 'for u in \$(awk -F: "{print \$1}" /etc/passwd); do crontab -lu "\$u" 2>/dev/null | grep -E "schedule:run|schedule:work" | grep -v "dply-scheduler-tick" && echo "--dup-user-\$u--"; done' 2>/dev/null | head -c 2048)
if [ -z "\$DUP" ]; then
    emit no_duplicate_scheduler pass "no other scheduler-shaped cron lines found"
else
    emit no_duplicate_scheduler warn "another scheduler-like cron line was found — adding ours would create a duplicate"
fi
BASH;
    }

    /**
     * Parse the bundle output into structured results.
     *
     * @return list<array{key: string, status: string, message: string}>
     */
    public function parseResult(string $output): array
    {
        $results = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (! str_starts_with($line, 'DPLY_PREFLIGHT: ')) {
                continue;
            }
            $payload = substr($line, strlen('DPLY_PREFLIGHT: '));
            $parts = explode(' ', $payload, 3);
            if (count($parts) < 2) {
                continue;
            }
            [$key, $status] = [$parts[0], $parts[1]];
            $message = $parts[2] ?? '';

            if (! in_array($status, [self::STATUS_PASS, self::STATUS_WARN, self::STATUS_FAIL], true)) {
                continue;
            }
            $results[] = ['key' => $key, 'status' => $status, 'message' => trim($message)];
        }

        return $results;
    }

    /**
     * Inspect parsed results for structural failures (block Enable).
     *
     * @param  list<array{key: string, status: string, message: string}>  $results
     * @return list<array{key: string, status: string, message: string}>
     */
    public function structuralFailures(array $results): array
    {
        return array_values(array_filter(
            $results,
            fn (array $r): bool => $r['status'] === self::STATUS_FAIL && in_array($r['key'], self::STRUCTURAL_CHECKS, true),
        ));
    }

    /**
     * @param  list<array{key: string, status: string, message: string}>  $results
     * @return list<array{key: string, status: string, message: string}>
     */
    public function advisoryWarnings(array $results): array
    {
        return array_values(array_filter(
            $results,
            fn (array $r): bool => $r['status'] === self::STATUS_WARN,
        ));
    }

    /**
     * Run the bundle over SSH and return parsed results. Defensive on SSH
     * failure — returns an empty result, lets the caller decide whether to
     * block (typically: yes — we couldn't verify, refuse the Enable).
     *
     * @return list<array{key: string, status: string, message: string}>
     */
    public function run(Server $server, Site $site): array
    {
        try {
            $script = $this->buildScript($site);
            $out = $this->remote->runInlineBash($server, 'scheduler-preflight', $script, 30, false);

            return $this->parseResult((string) $out->getBuffer());
        } catch (\Throwable $e) {
            Log::warning('scheduler.preflight.ssh_failed', [
                'server_id' => $server->id,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
