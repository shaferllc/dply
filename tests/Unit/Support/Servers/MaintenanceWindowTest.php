<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Models\Server;
use App\Support\Servers\MaintenanceWindow;
use Carbon\CarbonImmutable;

/**
 * Builds an unsaved Server carrying only the meta we care about — the advisory
 * MaintenanceWindow reader never touches the database, so no DB is needed.
 *
 * @param  array<string, mixed>  $meta
 */
function maintenanceServer(array $meta): Server
{
    $server = new Server;
    $server->meta = $meta;

    return $server;
}

/** Three-letter day key (mon..sun) for a given moment, matching the form's values. */
function dayKeyFor(CarbonImmutable $when): string
{
    return strtolower(substr($when->englishDayOfWeek, 0, 3));
}

test('reads the days the settings form actually persists (maintenance_days)', function (): void {
    // Regression: the form saves meta.maintenance_days but the reader used to look
    // at meta.maintenance_weekdays, so enabled() was always false and the
    // inside-window badge + disruptive-action warnings never fired.
    $now = CarbonImmutable::parse('2026-06-15 03:00:00', 'UTC');

    $server = maintenanceServer([
        'maintenance_days' => [dayKeyFor($now)],
        'maintenance_start' => '02:00',
        'maintenance_end' => '04:00',
    ]);

    $window = MaintenanceWindow::forServer($server);

    expect($window->enabled())->toBeTrue()
        ->and($window->containsNow($now))->toBeTrue()
        ->and($window->summary())->toContain('02:00')
        ->and($window->summary())->toContain('04:00');
});

test('containsNow is false outside the configured hours on a configured day', function (): void {
    $now = CarbonImmutable::parse('2026-06-15 05:30:00', 'UTC');

    $server = maintenanceServer([
        'maintenance_days' => [dayKeyFor($now)],
        'maintenance_start' => '02:00',
        'maintenance_end' => '04:00',
    ]);

    expect(MaintenanceWindow::forServer($server)->containsNow($now))->toBeFalse();
});

test('containsNow is false on a day not in the list even within the hours', function (): void {
    $now = CarbonImmutable::parse('2026-06-15 03:00:00', 'UTC'); // inside hours
    $otherDay = dayKeyFor($now->addDay());

    $server = maintenanceServer([
        'maintenance_days' => [$otherDay],
        'maintenance_start' => '02:00',
        'maintenance_end' => '04:00',
    ]);

    expect(MaintenanceWindow::forServer($server)->containsNow($now))->toBeFalse();
});

test('window is evaluated in the preferred timezone, not the server OS clock', function (): void {
    // 10:00 UTC == 03:00 in Los Angeles (PDT, UTC-7) in June.
    $instant = CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC');
    $laDay = dayKeyFor($instant->setTimezone('America/Los_Angeles'));

    $days = [$laDay];
    $hours = ['maintenance_start' => '02:00', 'maintenance_end' => '04:00'];

    $laServer = maintenanceServer(['timezone' => 'America/Los_Angeles', 'maintenance_days' => $days] + $hours);
    $utcServer = maintenanceServer(['timezone' => 'UTC', 'maintenance_days' => $days] + $hours);

    // Same absolute instant: inside the window in LA (03:00), outside in UTC (10:00).
    expect(MaintenanceWindow::forServer($laServer)->containsNow($instant))->toBeTrue()
        ->and(MaintenanceWindow::forServer($utcServer)->containsNow($instant))->toBeFalse();
});

test('overnight window crossing midnight contains times on both sides', function (): void {
    // Window 22:00 -> 04:00 configured for the start day.
    $startDayNow = CarbonImmutable::parse('2026-06-15 23:00:00', 'UTC'); // late evening, same day
    $startDay = dayKeyFor($startDayNow);

    $server = maintenanceServer([
        'maintenance_days' => [$startDay],
        'maintenance_start' => '22:00',
        'maintenance_end' => '04:00',
    ]);

    $window = MaintenanceWindow::forServer($server);

    // 23:00 on the configured day is inside (after start).
    expect($window->containsNow($startDayNow))->toBeTrue()
        // 02:00 the next calendar day is still inside (before end, prior day's slot).
        ->and($window->containsNow(CarbonImmutable::parse('2026-06-16 02:00:00', 'UTC')))->toBeTrue()
        // 05:00 the next day is past the end → outside.
        ->and($window->containsNow(CarbonImmutable::parse('2026-06-16 05:00:00', 'UTC')))->toBeFalse();
});

test('enabled is false unless days, start and end are all present', function (): void {
    $now = CarbonImmutable::parse('2026-06-15 03:00:00', 'UTC');
    $day = dayKeyFor($now);

    $noDays = maintenanceServer(['maintenance_days' => [], 'maintenance_start' => '02:00', 'maintenance_end' => '04:00']);
    $noStart = maintenanceServer(['maintenance_days' => [$day], 'maintenance_end' => '04:00']);
    $noEnd = maintenanceServer(['maintenance_days' => [$day], 'maintenance_start' => '02:00']);
    $nothing = maintenanceServer([]);

    expect(MaintenanceWindow::forServer($noDays)->enabled())->toBeFalse()
        ->and(MaintenanceWindow::forServer($noStart)->enabled())->toBeFalse()
        ->and(MaintenanceWindow::forServer($noEnd)->enabled())->toBeFalse()
        ->and(MaintenanceWindow::forServer($nothing)->enabled())->toBeFalse()
        ->and(MaintenanceWindow::forServer($noDays)->containsNow($now))->toBeFalse()
        ->and(MaintenanceWindow::forServer($nothing)->summary())->toBeNull();
});

test('falls back to the legacy maintenance_weekdays meta key', function (): void {
    $now = CarbonImmutable::parse('2026-06-15 03:00:00', 'UTC');

    $server = maintenanceServer([
        'maintenance_weekdays' => [dayKeyFor($now)],
        'maintenance_start' => '02:00',
        'maintenance_end' => '04:00',
    ]);

    expect(MaintenanceWindow::forServer($server)->containsNow($now))->toBeTrue();
});

test('an invalid timezone falls back to UTC instead of throwing', function (): void {
    $now = CarbonImmutable::parse('2026-06-15 03:00:00', 'UTC');

    $server = maintenanceServer([
        'timezone' => 'Not/AZone',
        'maintenance_days' => [dayKeyFor($now)],
        'maintenance_start' => '02:00',
        'maintenance_end' => '04:00',
    ]);

    expect(MaintenanceWindow::forServer($server)->containsNow($now))->toBeTrue();
});
