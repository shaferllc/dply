<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueDoctorCommandTest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function doctorReport(): array
{
    $exit = Artisan::call('dply:queue:doctor', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    return ['exit' => $exit, 'report' => is_array($decoded) ? $decoded : []];
}

/** @return array<string, mixed>|null */
function doctorCheck(array $report, string $name): ?array
{
    foreach (($report['checks'] ?? []) as $check) {
        if (($check['name'] ?? '') === $name) {
            return $check;
        }
    }

    return null;
}

it('fails when the job store shares the control-plane database', function () {
    // The default state: `dply_queue` falls through to `DB_*`. Reported as a
    // failure not because sharing is invalid, but because the module's code is
    // written as though it is not true — that gap should be a decision.
    config(['database.connections.dply_queue.database' => config('database.connections.pgsql.database')]);
    config(['database.connections.dply_queue.host' => config('database.connections.pgsql.host')]);
    config(['database.connections.dply_queue.port' => config('database.connections.pgsql.port')]);

    $result = doctorReport();

    expect($result['exit'])->toBe(1);
    expect(doctorCheck($result['report'], 'store_isolation')['ok'])->toBeFalse();
    expect($result['report']['summary']['store'])->toContain('SHARED');
});

it('names the env vars that fix it rather than just reporting the problem', function () {
    config(['database.connections.dply_queue.database' => config('database.connections.pgsql.database')]);

    $detail = (string) doctorCheck(doctorReport()['report'], 'store_isolation')['detail'];

    expect($detail)->toContain('DPLY_QUEUE_DB_HOST');
    expect($detail)->toContain('php artisan migrate');
});

it('flags an unreachable public endpoint as unusable rather than merely unset', function () {
    config(['queue_service.public_url' => null, 'dply.public_app_url' => '']);

    $check = doctorCheck(doctorReport()['report'], 'public_endpoint');

    expect($check['ok'])->toBeFalse();
    expect($check['detail'])->toContain('unreachable');
});

it('reports the kill switch state', function () {
    config(['queue_service.enabled' => false]);

    $check = doctorCheck(doctorReport()['report'], 'kill_switch');

    expect($check['ok'])->toBeFalse();
    expect($check['detail'])->toContain('DPLY_QUEUE_ENABLED');
});

it('confirms the per-table autovacuum tuning survived', function () {
    // The migration sets scale factors of 0.01 and fillfactor 80 because
    // without them this table degrades under sustained load. A restore or a
    // hand-rebuilt table can silently drop them.
    $check = doctorCheck(doctorReport()['report'], 'table_health');

    expect($check['detail'])->toContain('autovacuum tuning: present');
});

it('reports the drain ceiling per tier', function () {
    config(['queue_service.tiers' => [
        'standard' => ['label' => 'Standard', 'max_queue_depth' => 100, 'requests_per_minute' => 600, 'price_cents' => 900],
    ]]);

    $check = doctorCheck(doctorReport()['report'], 'drain_ceiling');

    // 600 requests/minute, one delete per job → 10 jobs/second.
    expect($check['detail'])->toContain('standard ~10 jobs/s');
});
