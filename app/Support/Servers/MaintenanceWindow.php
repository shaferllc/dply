<?php

namespace App\Support\Servers;

use App\Models\Server;
use Carbon\CarbonImmutable;
use Carbon\CarbonTimeZone;

/**
 * Reads a server's maintenance-window preferences from `meta` and computes whether
 * the server is currently inside the window. All evaluation happens in the user's
 * preferred timezone (`meta.timezone`, default UTC) — not the server OS clock.
 *
 * `meta.maintenance_weekdays` — list of three-letter day keys (mon..sun)
 * `meta.maintenance_start`     — "HH:MM" local-time string, optional
 * `meta.maintenance_end`       — "HH:MM" local-time string, optional
 *
 * The window is treated as configured only when *all three* are present (any day,
 * a start, and an end). A missing field means "unconfigured", and `enabled()`
 * returns false — callers treat that as "no window set, allow disruptive work".
 */
class MaintenanceWindow
{
    /** @var array<int, string> */
    private const DAY_ORDER = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct(
        public readonly ?CarbonTimeZone $timezone,
        /** @var list<string> */
        public readonly array $days,
        public readonly ?string $start,
        public readonly ?string $end,
    ) {}

    public static function forServer(Server $server): self
    {
        $meta = $server->meta ?? [];

        $tzName = is_string($meta['timezone'] ?? null) && $meta['timezone'] !== ''
            ? $meta['timezone']
            : (string) config('app.timezone', 'UTC');
        try {
            $tz = new CarbonTimeZone($tzName);
        } catch (\Throwable) {
            $tz = new CarbonTimeZone('UTC');
        }

        $rawDays = is_array($meta['maintenance_weekdays'] ?? null) ? $meta['maintenance_weekdays'] : [];
        $days = array_values(array_filter(array_map(
            fn ($d) => is_string($d) ? strtolower($d) : '',
            $rawDays
        ), fn ($d) => in_array($d, self::DAY_ORDER, true)));

        $start = is_string($meta['maintenance_start'] ?? null) && preg_match('/^\d{2}:\d{2}$/', $meta['maintenance_start']) === 1
            ? $meta['maintenance_start']
            : null;
        $end = is_string($meta['maintenance_end'] ?? null) && preg_match('/^\d{2}:\d{2}$/', $meta['maintenance_end']) === 1
            ? $meta['maintenance_end']
            : null;

        return new self($tz, $days, $start, $end);
    }

    /** True only when day list, start, and end are all configured. */
    public function enabled(): bool
    {
        return $this->days !== [] && $this->start !== null && $this->end !== null;
    }

    /**
     * Whether `now` (in the server's preferred timezone) falls inside the configured
     * window. When the window crosses midnight (start > end), we treat it as the union
     * of "today after start" and "tomorrow before end on the prior day's slot".
     */
    public function containsNow(?CarbonImmutable $now = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $now = ($now ?? CarbonImmutable::now())->setTimezone($this->timezone);

        return $this->contains($now);
    }

    /**
     * Human-friendly summary, e.g. "Mon, Wed, Fri · 02:00–04:00 (America/Los_Angeles)".
     */
    public function summary(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $dayLabels = [
            'mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu',
            'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun',
        ];
        $ordered = array_values(array_filter(self::DAY_ORDER, fn ($d) => in_array($d, $this->days, true)));
        $names = implode(', ', array_map(fn ($d) => $dayLabels[$d], $ordered));

        return $names.' · '.$this->start.'–'.$this->end.' ('.$this->timezone->getName().')';
    }

    /**
     * Friendly explanation suitable for confirm dialogs / toasts when callers attempt a
     * disruptive action outside the window. Returns null when no window is configured.
     */
    public function outsideWindowMessage(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        return 'Outside maintenance window ('.$this->summary().'). Continue anyway?';
    }

    private function contains(CarbonImmutable $now): bool
    {
        // Two cases: same-day window (start < end) and overnight window (start > end).
        $today = $this->dayKey($now);
        $startToday = $this->atToday($now, $this->start);
        $endToday = $this->atToday($now, $this->end);

        if ($this->start <= $this->end) {
            // Window is contained within a single calendar day.
            return in_array($today, $this->days, true)
                && $now->greaterThanOrEqualTo($startToday)
                && $now->lessThan($endToday);
        }

        // Overnight: e.g. start 22:00, end 04:00. Window is the union of:
        //   today (if today is configured) from start..midnight
        //   today (if yesterday is configured) from midnight..end
        $yesterday = $this->dayKey($now->subDay());

        if (in_array($today, $this->days, true) && $now->greaterThanOrEqualTo($startToday)) {
            return true;
        }
        if (in_array($yesterday, $this->days, true) && $now->lessThan($endToday)) {
            return true;
        }

        return false;
    }

    private function dayKey(CarbonImmutable $when): string
    {
        return strtolower(substr($when->englishDayOfWeek, 0, 3));
    }

    private function atToday(CarbonImmutable $now, string $hhmm): CarbonImmutable
    {
        [$h, $m] = explode(':', $hhmm);

        return $now->setTime((int) $h, (int) $m, 0, 0);
    }
}
