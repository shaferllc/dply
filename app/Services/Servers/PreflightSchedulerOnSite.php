<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerSchedulerHeartbeat;
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

    /** Laravel Enable — all structural checks. */
    public const STRUCTURAL_CHECKS = [
        'site_release_present',
        'php_binary',
        'artisan_file',
        'laravel_boots',
        'cron_user_access',
    ];

    /** Rails / generic Enable — release + crontab access only. */
    public const STRUCTURAL_CHECKS_MINIMAL = [
        'site_release_present',
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
     * @return list<string>
     */
    public function structuralChecksForKind(string $kind): array
    {
        return $kind === ServerSchedulerHeartbeat::KIND_LARAVEL
            ? self::STRUCTURAL_CHECKS
            : self::STRUCTURAL_CHECKS_MINIMAL;
    }

    /**
     * Build the bash bundle that runs every check and stamps results.
     */
    public function buildScript(Site $site, string $kind = ServerSchedulerHeartbeat::KIND_LARAVEL): string
    {
        return $kind === ServerSchedulerHeartbeat::KIND_LARAVEL
            ? $this->buildLaravelScript($site)
            : $this->buildMinimalScript($site);
    }

    protected function buildLaravelScript(Site $site): string
    {
        $repoPath = rtrim($site->effectiveRepositoryPath(), '/');
        $currentDir = $repoPath.'/current';
        $deployUser = $site->effectiveSystemUser($site->server) ?: 'dply';

        $repo = escapeshellarg($currentDir);
        $user = escapeshellarg($deployUser);

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
    TASK_COUNT=\$(printf '%s\\n' "\$SCHEDULE_OUT" | grep -cE '^\\s+\\* ' 2>/dev/null || true)
    if [ "\$TASK_COUNT" -gt 0 ] 2>/dev/null; then
        emit scheduler_has_tasks pass "found \$TASK_COUNT scheduled task(s)"
    else
        emit scheduler_has_tasks warn "schedule:list returned no tasks — Enable will create a scheduler that has nothing to do until you register tasks"
    fi
else
    emit laravel_boots fail "schedule:list exited \$SCHEDULE_EXIT — app may be broken (check .env, deps, migrations)"
fi

# 5. cron access for the deploy user
if sudo -nu {$user} crontab -l >/dev/null 2>&1; then
    emit cron_user_access pass "user {$deployUser} can read crontab"
else
    if sudo -nu {$user} sh -c 'echo "" | crontab - && crontab -l' >/dev/null 2>&1; then
        emit cron_user_access pass "user {$deployUser} can write crontab"
    else
        emit cron_user_access fail "user {$deployUser} cannot access crontab — check sudo policy"
    fi
fi

# 6. no duplicate scheduler under another user (advisory)
DUP=\$(sudo -n bash -c 'for u in \$(awk -F: "{print \$1}" /etc/passwd); do crontab -lu "\$u" 2>/dev/null | grep -E "schedule:run|schedule:work" | grep -v "dply-scheduler-tick" && echo "--dup-user-\$u--"; done' 2>/dev/null | head -c 2048)
if [ -z "\$DUP" ]; then
    emit no_duplicate_scheduler pass "no other scheduler-shaped cron lines found"
else
    emit no_duplicate_scheduler warn "another scheduler-like cron line was found — adding ours would create a duplicate"
fi
BASH;
    }

    protected function buildMinimalScript(Site $site): string
    {
        $repoPath = rtrim($site->effectiveRepositoryPath(), '/');
        $currentDir = $repoPath.'/current';
        $deployUser = $site->effectiveSystemUser($site->server) ?: 'dply';

        $repo = escapeshellarg($currentDir);
        $user = escapeshellarg($deployUser);

        return <<<BASH
set +e
emit() { printf 'DPLY_PREFLIGHT: %s %s %s\\n' "\$1" "\$2" "\$3"; }

# 1. site release present
if [ -d {$repo} ]; then
    emit site_release_present pass "current release found at {$currentDir}"
else
    emit site_release_present fail "no current release at {$currentDir} — deploy the site first"
fi

# 2. cron access for the deploy user
if sudo -nu {$user} crontab -l >/dev/null 2>&1; then
    emit cron_user_access pass "user {$deployUser} can read crontab"
else
    if sudo -nu {$user} sh -c 'echo "" | crontab - && crontab -l' >/dev/null 2>&1; then
        emit cron_user_access pass "user {$deployUser} can write crontab"
    else
        emit cron_user_access fail "user {$deployUser} cannot access crontab — check sudo policy"
    fi
fi

# 3. no duplicate scheduler under another user (advisory)
DUP=\$(sudo -n bash -c 'for u in \$(awk -F: "{print \$1}" /etc/passwd); do crontab -lu "\$u" 2>/dev/null | grep -E "schedule:run|schedule:work|whenever|sidekiq" | grep -v "dply-scheduler-tick" && echo "--dup-user-\$u--"; done' 2>/dev/null | head -c 2048)
if [ -z "\$DUP" ]; then
    emit no_duplicate_scheduler pass "no other scheduler-shaped cron lines found"
else
    emit no_duplicate_scheduler warn "another scheduler-like cron line was found — adding ours would create a duplicate"
fi
BASH;
    }

    /**
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
     * @param  list<array{key: string, status: string, message: string}>  $results
     * @return list<array{key: string, status: string, message: string}>
     */
    public function structuralFailures(array $results, string $kind = ServerSchedulerHeartbeat::KIND_LARAVEL): array
    {
        $checks = $this->structuralChecksForKind($kind);

        return array_values(array_filter(
            $results,
            fn (array $r): bool => $r['status'] === self::STATUS_FAIL && in_array($r['key'], $checks, true),
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
     * @return list<array{key: string, status: string, message: string}>
     */
    public function run(
        Server $server,
        Site $site,
        string $kind = ServerSchedulerHeartbeat::KIND_LARAVEL,
    ): array {
        try {
            $script = $this->buildScript($site, $kind);
            $out = $this->remote->runInlineBash($server, 'scheduler-preflight', $script, 30, false);

            return $this->parseResult((string) $out->getBuffer());
        } catch (\Throwable $e) {
            Log::warning('scheduler.preflight.ssh_failed', [
                'server_id' => $server->id,
                'site_id' => $site->id,
                'scheduler_kind' => $kind,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
