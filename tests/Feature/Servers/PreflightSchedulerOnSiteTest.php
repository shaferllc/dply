<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\PreflightSchedulerOnSiteTest;
use App\Services\Servers\PreflightSchedulerOnSite;
test('parse all pass', function () {
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

    expect($results)->toHaveCount(7);
    expect($svc->structuralFailures($results))->toBe([]);
    expect($svc->advisoryWarnings($results))->toBe([]);
});
test('structural failure blocks', function () {
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

    expect($failures)->toHaveCount(2);
    expect(array_column($failures, 'key'))->toBe(['php_binary', 'laravel_boots']);
});
test('advisory warning does not block', function () {
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

    expect($svc->structuralFailures($results))->toBeEmpty('advisory warnings must not surface as structural blockers');
    expect($svc->advisoryWarnings($results))->toHaveCount(2);
});
test('garbage lines are ignored', function () {
    $output = <<<OUT
some unrelated noise
DPLY_PREFLIGHT: site_release_present pass ok
not a preflight line either
DPLY_PREFLIGHT: php_binary bogus status — should be skipped
DPLY_PREFLIGHT: artisan_file pass ok
OUT;

    $svc = new PreflightSchedulerOnSite($this->app->make(\App\Services\Servers\ExecuteRemoteTaskOnServer::class));
    $results = $svc->parseResult($output);

    expect($results)->toHaveCount(2, 'only well-formed lines with valid status are parsed');
    expect(array_column($results, 'key'))->toBe(['site_release_present', 'artisan_file']);
});
test('empty output returns empty results', function () {
    $svc = new PreflightSchedulerOnSite($this->app->make(\App\Services\Servers\ExecuteRemoteTaskOnServer::class));

    expect($svc->parseResult(''))->toBe([]);
    expect($svc->parseResult("\n\n"))->toBe([]);
});
