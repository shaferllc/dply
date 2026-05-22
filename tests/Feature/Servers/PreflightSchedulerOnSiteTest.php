<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Services\Servers\PreflightSchedulerOnSite;
use Tests\TestCase;

/**
 * Parser-level tests for the preflight bundle. SSH wiring is tested via the
 * Enable-flow test that mocks the underlying remote executor.
 */
class PreflightSchedulerOnSiteTest extends TestCase
{
    public function test_parse_all_pass(): void
    {
        $output = <<<OUT
DPLY_PREFLIGHT: site_release_present pass current release found at /home/dply/site/current
DPLY_PREFLIGHT: php_binary pass php available at /usr/bin/php
DPLY_PREFLIGHT: artisan_file pass artisan file present
DPLY_PREFLIGHT: laravel_boots pass schedule:list ran successfully
DPLY_PREFLIGHT: scheduler_has_tasks pass found 5 scheduled task(s)
DPLY_PREFLIGHT: cron_user_access pass user dply can read crontab
DPLY_PREFLIGHT: no_duplicate_scheduler pass no other scheduler-shaped cron lines found
OUT;

        $svc = new PreflightSchedulerOnSite($this->app->make(\App\Services\Servers\ExecuteRemoteTaskOnServer::class));
        $results = $svc->parseResult($output);

        $this->assertCount(7, $results);
        $this->assertSame([], $svc->structuralFailures($results));
        $this->assertSame([], $svc->advisoryWarnings($results));
    }

    public function test_structural_failure_blocks(): void
    {
        $output = <<<OUT
DPLY_PREFLIGHT: site_release_present pass found
DPLY_PREFLIGHT: php_binary fail php binary not found on PATH
DPLY_PREFLIGHT: artisan_file pass artisan file present
DPLY_PREFLIGHT: laravel_boots fail schedule:list exited 1
DPLY_PREFLIGHT: scheduler_has_tasks warn no tasks
DPLY_PREFLIGHT: cron_user_access pass ok
DPLY_PREFLIGHT: no_duplicate_scheduler pass ok
OUT;

        $svc = new PreflightSchedulerOnSite($this->app->make(\App\Services\Servers\ExecuteRemoteTaskOnServer::class));
        $results = $svc->parseResult($output);
        $failures = $svc->structuralFailures($results);

        $this->assertCount(2, $failures);
        $this->assertSame(['php_binary', 'laravel_boots'], array_column($failures, 'key'));
    }

    public function test_advisory_warning_does_not_block(): void
    {
        $output = <<<OUT
DPLY_PREFLIGHT: site_release_present pass ok
DPLY_PREFLIGHT: php_binary pass ok
DPLY_PREFLIGHT: artisan_file pass ok
DPLY_PREFLIGHT: laravel_boots pass ok
DPLY_PREFLIGHT: scheduler_has_tasks warn no tasks registered yet
DPLY_PREFLIGHT: cron_user_access pass ok
DPLY_PREFLIGHT: no_duplicate_scheduler warn duplicate cron line under user root
OUT;

        $svc = new PreflightSchedulerOnSite($this->app->make(\App\Services\Servers\ExecuteRemoteTaskOnServer::class));
        $results = $svc->parseResult($output);

        $this->assertEmpty($svc->structuralFailures($results), 'advisory warnings must not surface as structural blockers');
        $this->assertCount(2, $svc->advisoryWarnings($results));
    }

    public function test_garbage_lines_are_ignored(): void
    {
        $output = <<<OUT
some unrelated noise
DPLY_PREFLIGHT: site_release_present pass ok
not a preflight line either
DPLY_PREFLIGHT: php_binary bogus status — should be skipped
DPLY_PREFLIGHT: artisan_file pass ok
OUT;

        $svc = new PreflightSchedulerOnSite($this->app->make(\App\Services\Servers\ExecuteRemoteTaskOnServer::class));
        $results = $svc->parseResult($output);

        $this->assertCount(2, $results, 'only well-formed lines with valid status are parsed');
        $this->assertSame(['site_release_present', 'artisan_file'], array_column($results, 'key'));
    }

    public function test_empty_output_returns_empty_results(): void
    {
        $svc = new PreflightSchedulerOnSite($this->app->make(\App\Services\Servers\ExecuteRemoteTaskOnServer::class));

        $this->assertSame([], $svc->parseResult(''));
        $this->assertSame([], $svc->parseResult("\n\n"));
    }
}
