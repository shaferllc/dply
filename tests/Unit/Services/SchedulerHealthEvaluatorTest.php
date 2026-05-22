<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SchedulerHealthEvaluatorTest;

use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Services\Servers\SchedulerHealthEvaluator;
use Carbon\Carbon;

function heartbeat(array $attrs = []): ServerSchedulerHeartbeat
{
    $hb = new ServerSchedulerHeartbeat;
    $hb->forceFill(array_merge([
        'id' => '01HZTESTHB000000000000000A',
        'server_id' => '01HZTESTSV000000000000000A',
        'site_id' => '01HZTESTST000000000000000A',
        'scheduler_kind' => ServerSchedulerHeartbeat::KIND_LARAVEL,
        'cron_expression' => '* * * * *',
        'last_tick_at' => Carbon::parse('2026-05-19T12:00:00Z'),
        'consecutive_misses' => 0,
        'first_seen_at' => Carbon::parse('2026-05-19T10:00:00Z'),
        'circuit_open' => false,
        'output_capture_enabled' => true,
    ], $attrs));

    return $hb;
}
function cron(bool $enabled): ServerCronJob
{
    $cron = new ServerCronJob;
    $cron->forceFill([
        'enabled' => $enabled,
    ]);

    return $cron;
}
test('recent tick with minute cadence is healthy', function () {
    $now = Carbon::parse('2026-05-19T12:00:30Z');
    // 30s after last tick
    $eval = new SchedulerHealthEvaluator;

    expect($eval->evaluate(heartbeat(), null, $now))->toBe(SchedulerHealthEvaluator::STATE_HEALTHY);
});
test('one missed window at minute cadence is amber', function () {
    // last tick 12:00:00; minute cadence has 2× grace = 120s. Deadline =
    // now - 120s. To have exactly 1 missed window (the 12:01:00 fire), we
    // need deadline ≥ 12:01:00 and < 12:02:00. At 12:03:30 the deadline
    // is 12:01:30 — the 12:01 fire is missed; 12:02 isn't yet past grace.
    $now = Carbon::parse('2026-05-19T12:03:30Z');
    $eval = new SchedulerHealthEvaluator;

    $state = $eval->evaluate(heartbeat(), null, $now);
    expect($state)->toBe(SchedulerHealthEvaluator::STATE_AMBER);
});
test('three missed windows is red', function () {
    // last tick at 12:00:00; at 12:05:00 we've missed 12:01, 12:02, 12:03,
    // 12:04 (4 windows past the grace) — well over the RED threshold of 3.
    $now = Carbon::parse('2026-05-19T12:05:00Z');
    $eval = new SchedulerHealthEvaluator;

    expect($eval->evaluate(heartbeat(), null, $now))->toBe(SchedulerHealthEvaluator::STATE_RED);
});
test('paused cron overrides staleness', function () {
    // last tick 24h ago — would be RED — but the cron is paused.
    $hb = heartbeat(['last_tick_at' => Carbon::parse('2026-05-18T12:00:00Z')]);
    $now = Carbon::parse('2026-05-19T12:00:00Z');

    $eval = new SchedulerHealthEvaluator;
    expect($eval->evaluate($hb, cron(false), $now))->toBe(SchedulerHealthEvaluator::STATE_PAUSED);
});
test('waiting state during grace window before first tick', function () {
    $hb = heartbeat([
        'last_tick_at' => null,
        'first_seen_at' => Carbon::parse('2026-05-19T12:00:00Z'),
        'consecutive_misses' => 0,
    ]);
    $now = Carbon::parse('2026-05-19T12:01:00Z');
    // 60s after enable; within 180s grace
    $eval = new SchedulerHealthEvaluator;

    expect($eval->evaluate($hb, null, $now))->toBe(SchedulerHealthEvaluator::STATE_WAITING);
});
test('after waiting grace no tick does not cascade to amber', function () {
    // last_tick_at is null; computeMisses returns 0 for that case. So
    // even past the waiting grace, the evaluator should NOT misclassify
    // a never-ticked scheduler as AMBER from the time math alone — that
    // semantic belongs to the persistent consecutive_misses field which
    // the runner will set.
    $hb = heartbeat([
        'last_tick_at' => null,
        'first_seen_at' => Carbon::parse('2026-05-19T12:00:00Z'),
        'consecutive_misses' => 0,
    ]);
    $now = Carbon::parse('2026-05-19T12:10:00Z');
    // 10 min past first_seen
    $eval = new SchedulerHealthEvaluator;

    expect($eval->evaluate($hb, null, $now))->toBe(SchedulerHealthEvaluator::STATE_HEALTHY);
});
test('persisted consecutive misses pushes state even if clock says otherwise', function () {
    // Runner had marked 5 missed windows; agent then pushed a stale heartbeat
    // (last_tick_at didn't advance — same value as before). Page should still
    // show RED because the persistent counter says so.
    $hb = heartbeat([
        'last_tick_at' => Carbon::parse('2026-05-19T11:59:00Z'),
        'consecutive_misses' => 5,
    ]);
    $now = Carbon::parse('2026-05-19T12:00:00Z');
    // 1 minute later — would be HEALTHY by time alone
    $eval = new SchedulerHealthEvaluator;

    expect($eval->evaluate($hb, null, $now))->toBe(SchedulerHealthEvaluator::STATE_RED);
});
test('hourly cadence uses proportional grace', function () {
    // Hourly scheduler. Last tick 12:00. At 13:30 (90 min later) we've
    // passed one expected fire (13:00) — but with cadence-proportional
    // grace (2× cadence = 2h), we're not yet stale.
    $hb = heartbeat([
        'cron_expression' => '0 * * * *',
        'last_tick_at' => Carbon::parse('2026-05-19T12:00:00Z'),
    ]);
    $now = Carbon::parse('2026-05-19T13:30:00Z');
    $eval = new SchedulerHealthEvaluator;

    expect($eval->evaluate($hb, null, $now))->toBe(SchedulerHealthEvaluator::STATE_HEALTHY);
});
test('hourly cadence red after three plus missed hours', function () {
    $hb = heartbeat([
        'cron_expression' => '0 * * * *',
        'last_tick_at' => Carbon::parse('2026-05-19T12:00:00Z'),
    ]);

    // 12+8h = 20:00. With 2h grace, deadline is 18:00. Missed: 13/14/15/16/17/18 = 6.
    $now = Carbon::parse('2026-05-19T20:00:00Z');
    $eval = new SchedulerHealthEvaluator;

    expect($eval->evaluate($hb, null, $now))->toBe(SchedulerHealthEvaluator::STATE_RED);
});
test('bogus cron expression does not throw', function () {
    $hb = heartbeat(['cron_expression' => 'definitely-not-cron']);
    $eval = new SchedulerHealthEvaluator;

    expect($eval->evaluate($hb, null, Carbon::parse('2026-05-19T13:00:00Z')))->toBe(SchedulerHealthEvaluator::STATE_HEALTHY);
});
