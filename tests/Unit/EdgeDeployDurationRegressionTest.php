<?php

namespace Tests\Unit\EdgeDeployDurationRegressionTest;

use App\Models\EdgeDeployment;
use App\Modules\Edge\Services\EdgeDeployDurationRegression;
use Illuminate\Support\Carbon;

/**
 * Carbon 3 returns a SIGNED diff, so `$later->diffInMilliseconds($earlier)` is
 * negative and a surrounding `max(0, …)` clamps every duration to zero. Nothing
 * throws; the metric just reads 0 forever and looks like a missing value.
 *
 * These pin the argument order (earlier -> later) at the one place that computes
 * a deploy duration, plus the median/threshold rules that decide whether a
 * slow deploy is worth telling anyone about.
 */
function durationMs(EdgeDeployment $deployment): ?int
{
    $method = new \ReflectionMethod(EdgeDeployDurationRegression::class, 'durationMs');
    $method->setAccessible(true);

    return $method->invoke(app(EdgeDeployDurationRegression::class), $deployment);
}

function median(array $values): float
{
    $method = new \ReflectionMethod(EdgeDeployDurationRegression::class, 'median');
    $method->setAccessible(true);

    return $method->invoke(app(EdgeDeployDurationRegression::class), $values);
}

function deployment(int $seconds): EdgeDeployment
{
    $created = Carbon::parse('2026-01-01 00:00:00');
    $d = new EdgeDeployment;
    $d->forceFill([
        'created_at' => $created,
        'published_at' => $created->copy()->addSeconds($seconds),
    ]);

    return $d;
}

test('duration is positive — the Carbon 3 signed diff is not clamped to zero', function () {
    expect(durationMs(deployment(120)))->toBe(120_000);
});

test('sanity: the reversed argument order really would have produced zero', function () {
    // Documents the bug this test file exists to prevent. If Carbon ever changes
    // back to an absolute default, this fails and the guard can be revisited.
    // Carbon 3 returns a float, which is why the production code casts to int.
    $d = deployment(120);

    expect((int) max(0, $d->published_at->diffInMilliseconds($d->created_at)))->toBe(0);
    expect((int) max(0, $d->created_at->diffInMilliseconds($d->published_at)))->toBe(120_000);
});

test('duration is null when either timestamp is missing', function () {
    $d = new EdgeDeployment;
    $d->forceFill(['created_at' => Carbon::parse('2026-01-01 00:00:00'), 'published_at' => null]);

    expect(durationMs($d))->toBeNull();
});

test('median ignores a pathological outlier that would drag a mean upward', function () {
    // 58,59,60,61,62,600 -> mean 150, median 60.5. A mean baseline would hide a
    // 120s regression entirely; the median catches it.
    $values = [58, 59, 60, 61, 62, 600];

    expect(median($values))->toBe(60.5);
    expect(array_sum($values) / count($values))->toBeGreaterThan(120.0);
});

test('median handles odd and even counts', function () {
    expect(median([10]))->toBe(10.0);
    expect(median([10, 20, 30]))->toBe(20.0);
    expect(median([10, 20, 30, 40]))->toBe(25.0);
});

test('median does not depend on input order', function () {
    expect(median([600, 58, 61, 59, 62, 60]))->toBe(60.5);
});

test('evaluate stays silent when the feature is disabled', function () {
    config()->set('edge.duration_regression.enabled', false);

    expect(app(EdgeDeployDurationRegression::class)->evaluate(deployment(600)))->toBeNull();
});

test('evaluate stays silent for a deployment with no measurable duration', function () {
    // Guards the DB from being touched at all: a zero/negative duration bails
    // before the baseline query.
    config()->set('edge.duration_regression.enabled', true);

    expect(app(EdgeDeployDurationRegression::class)->evaluate(deployment(0)))->toBeNull();
});
