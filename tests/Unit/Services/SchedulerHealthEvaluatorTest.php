<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Services\Servers\SchedulerHealthEvaluator;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Pure-function tests for the staleness math from Q4. Extends Tests\TestCase
 * (not PHPUnit's directly) because Eloquent attribute casts hit the
 * connection resolver even on unsaved instances — we need the app booted.
 */
class SchedulerHealthEvaluatorTest extends TestCase
{
    private function heartbeat(array $attrs = []): ServerSchedulerHeartbeat
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

    private function cron(bool $enabled): ServerCronJob
    {
        $cron = new ServerCronJob;
        $cron->forceFill([
            'enabled' => $enabled,
        ]);

        return $cron;
    }

    public function test_recent_tick_with_minute_cadence_is_healthy(): void
    {
        $now = Carbon::parse('2026-05-19T12:00:30Z'); // 30s after last tick
        $eval = new SchedulerHealthEvaluator;

        $this->assertSame(
            SchedulerHealthEvaluator::STATE_HEALTHY,
            $eval->evaluate($this->heartbeat(), null, $now),
        );
    }

    public function test_one_missed_window_at_minute_cadence_is_amber(): void
    {
        // last tick 12:00:00; minute cadence has 2× grace = 120s. Deadline =
        // now - 120s. To have exactly 1 missed window (the 12:01:00 fire), we
        // need deadline ≥ 12:01:00 and < 12:02:00. At 12:03:30 the deadline
        // is 12:01:30 — the 12:01 fire is missed; 12:02 isn't yet past grace.
        $now = Carbon::parse('2026-05-19T12:03:30Z');
        $eval = new SchedulerHealthEvaluator;

        $state = $eval->evaluate($this->heartbeat(), null, $now);
        $this->assertSame(SchedulerHealthEvaluator::STATE_AMBER, $state);
    }

    public function test_three_missed_windows_is_red(): void
    {
        // last tick at 12:00:00; at 12:05:00 we've missed 12:01, 12:02, 12:03,
        // 12:04 (4 windows past the grace) — well over the RED threshold of 3.
        $now = Carbon::parse('2026-05-19T12:05:00Z');
        $eval = new SchedulerHealthEvaluator;

        $this->assertSame(
            SchedulerHealthEvaluator::STATE_RED,
            $eval->evaluate($this->heartbeat(), null, $now),
        );
    }

    public function test_paused_cron_overrides_staleness(): void
    {
        // last tick 24h ago — would be RED — but the cron is paused.
        $hb = $this->heartbeat(['last_tick_at' => Carbon::parse('2026-05-18T12:00:00Z')]);
        $now = Carbon::parse('2026-05-19T12:00:00Z');

        $eval = new SchedulerHealthEvaluator;
        $this->assertSame(
            SchedulerHealthEvaluator::STATE_PAUSED,
            $eval->evaluate($hb, $this->cron(false), $now),
        );
    }

    public function test_waiting_state_during_grace_window_before_first_tick(): void
    {
        $hb = $this->heartbeat([
            'last_tick_at' => null,
            'first_seen_at' => Carbon::parse('2026-05-19T12:00:00Z'),
            'consecutive_misses' => 0,
        ]);
        $now = Carbon::parse('2026-05-19T12:01:00Z'); // 60s after enable; within 180s grace
        $eval = new SchedulerHealthEvaluator;

        $this->assertSame(
            SchedulerHealthEvaluator::STATE_WAITING,
            $eval->evaluate($hb, null, $now),
        );
    }

    public function test_after_waiting_grace_no_tick_does_not_cascade_to_amber(): void
    {
        // last_tick_at is null; computeMisses returns 0 for that case. So
        // even past the waiting grace, the evaluator should NOT misclassify
        // a never-ticked scheduler as AMBER from the time math alone — that
        // semantic belongs to the persistent consecutive_misses field which
        // the runner will set.
        $hb = $this->heartbeat([
            'last_tick_at' => null,
            'first_seen_at' => Carbon::parse('2026-05-19T12:00:00Z'),
            'consecutive_misses' => 0,
        ]);
        $now = Carbon::parse('2026-05-19T12:10:00Z'); // 10 min past first_seen
        $eval = new SchedulerHealthEvaluator;

        $this->assertSame(
            SchedulerHealthEvaluator::STATE_HEALTHY,
            $eval->evaluate($hb, null, $now),
        );
    }

    public function test_persisted_consecutive_misses_pushes_state_even_if_clock_says_otherwise(): void
    {
        // Runner had marked 5 missed windows; agent then pushed a stale heartbeat
        // (last_tick_at didn't advance — same value as before). Page should still
        // show RED because the persistent counter says so.
        $hb = $this->heartbeat([
            'last_tick_at' => Carbon::parse('2026-05-19T11:59:00Z'),
            'consecutive_misses' => 5,
        ]);
        $now = Carbon::parse('2026-05-19T12:00:00Z'); // 1 minute later — would be HEALTHY by time alone
        $eval = new SchedulerHealthEvaluator;

        $this->assertSame(
            SchedulerHealthEvaluator::STATE_RED,
            $eval->evaluate($hb, null, $now),
        );
    }

    public function test_hourly_cadence_uses_proportional_grace(): void
    {
        // Hourly scheduler. Last tick 12:00. At 13:30 (90 min later) we've
        // passed one expected fire (13:00) — but with cadence-proportional
        // grace (2× cadence = 2h), we're not yet stale.
        $hb = $this->heartbeat([
            'cron_expression' => '0 * * * *',
            'last_tick_at' => Carbon::parse('2026-05-19T12:00:00Z'),
        ]);
        $now = Carbon::parse('2026-05-19T13:30:00Z');
        $eval = new SchedulerHealthEvaluator;

        $this->assertSame(
            SchedulerHealthEvaluator::STATE_HEALTHY,
            $eval->evaluate($hb, null, $now),
        );
    }

    public function test_hourly_cadence_red_after_three_plus_missed_hours(): void
    {
        $hb = $this->heartbeat([
            'cron_expression' => '0 * * * *',
            'last_tick_at' => Carbon::parse('2026-05-19T12:00:00Z'),
        ]);
        // 12+8h = 20:00. With 2h grace, deadline is 18:00. Missed: 13/14/15/16/17/18 = 6.
        $now = Carbon::parse('2026-05-19T20:00:00Z');
        $eval = new SchedulerHealthEvaluator;

        $this->assertSame(
            SchedulerHealthEvaluator::STATE_RED,
            $eval->evaluate($hb, null, $now),
        );
    }

    public function test_bogus_cron_expression_does_not_throw(): void
    {
        $hb = $this->heartbeat(['cron_expression' => 'definitely-not-cron']);
        $eval = new SchedulerHealthEvaluator;

        $this->assertSame(
            SchedulerHealthEvaluator::STATE_HEALTHY,
            $eval->evaluate($hb, null, Carbon::parse('2026-05-19T13:00:00Z')),
        );
    }
}
