<?php

namespace App\Services\Servers;

/**
 * Compares two systemd inventory snapshots (running-service rows) and emits high-level events.
 * Used to populate {@see ManagesServerSystemdServices} activity log without a guest-side daemon.
 */
final class ServerSystemdServiceSnapshotDiff
{
    /**
     * Patterns for systemd unit names that produce noisy "restart" events
     * because their ActiveEnterTimestamp legitimately ticks even when nothing
     * the operator cares about has changed (user managers re-arming on
     * login/cron, getty re-spawning on TTY activity, session-* / run-* /
     * scope units that are inherently transient). Started/stopped/state-
     * change events still fire — only the timestamp-flutter "restarted"
     * heuristic is suppressed for these.
     *
     * @var list<string>
     */
    private const RESTART_NOISE_PATTERNS = [
        '/^user@\d+\.service$/',
        '/^user-\d+\.slice$/',
        '/^user-runtime-dir@\d+\.service$/',
        '/^getty@.*\.service$/',
        '/^serial-getty@.*\.service$/',
        '/^session-\d+\.scope$/',
        '/^run-r[0-9a-f]+\.service$/',
        '/^run-r[0-9a-f]+\.scope$/',
        '/^systemd-tmpfiles-clean\.service$/',
        '/^systemd-tmpfiles-clean\.timer$/',
        '/^systemd-resolved\.service$/',
    ];

    /**
     * @param  list<array<string, mixed>>|null  $oldUnits
     * @param  list<array<string, mixed>>  $newUnits
     * @return list<array{at: string, kind: string, unit: string, label: string, detail: ?string}>
     */
    public function diff(?array $oldUnits, array $newUnits): array
    {
        if ($oldUnits === null || $oldUnits === []) {
            return [];
        }

        $oldMap = $this->mapByUnit($oldUnits);
        $newMap = $this->mapByUnit($newUnits);

        $now = now()->toIso8601String();
        $events = [];

        foreach ($newMap as $u => $new) {
            if (! isset($oldMap[$u])) {
                $events[] = $this->makeEvent($now, 'started', $new);

                continue;
            }
        }

        foreach ($oldMap as $u => $old) {
            if (! isset($newMap[$u])) {
                if (($old['active'] ?? '') === 'active') {
                    $events[] = [
                        'at' => $now,
                        'kind' => 'stopped',
                        'unit' => $u,
                        'label' => $this->label($u),
                        'detail' => __('No longer in the running list.'),
                    ];
                }

                continue;
            }
        }

        foreach ($newMap as $u => $new) {
            if (! isset($oldMap[$u])) {
                continue;
            }
            $old = $oldMap[$u];
            $oldActive = (string) ($old['active'] ?? '');
            $newActive = (string) ($new['active'] ?? '');
            $oldTs = trim((string) ($old['ts'] ?? ''));
            $newTs = trim((string) ($new['ts'] ?? ''));

            if ($oldActive === 'active' && $newActive === 'active' && $oldTs !== '' && $newTs !== '' && $oldTs !== $newTs) {
                if (! $this->isRestartNoise($u)) {
                    $events[] = $this->makeEvent($now, 'restarted', $new, __('ActiveEnterTimestamp changed (likely restart).'));
                }

                continue;
            }

            if ($oldActive !== 'active' && $newActive === 'active') {
                $events[] = $this->makeEvent($now, 'started', $new, __('Now active.'));

                continue;
            }

            if ($oldActive === 'active' && $newActive !== 'active') {
                $events[] = $this->makeEvent($now, 'state_changed', $new, $oldActive.' → '.$newActive);
            }
        }

        return $events;
    }

    private function isRestartNoise(string $unit): bool
    {
        foreach (self::RESTART_NOISE_PATTERNS as $pattern) {
            if (preg_match($pattern, $unit) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed> $newUnits
     * @param  array<string, mixed> $oldUnits
     * @return array<string, array<string, mixed>>
     */
    protected function mapByUnit(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['unit'])) {
                continue;
            }
            $u = (string) $row['unit'];
            $map[$u] = $row;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed> $row
     * @param  array<string, mixed> $rows
     * @return array{at: string, kind: string, unit: string, label: string, detail: ?string}
     */
    protected function makeEvent(string $at, string $kind, array $row, ?string $detail = null): array
    {
        $u = (string) ($row['unit'] ?? '');
        $label = isset($row['label']) && is_string($row['label']) && $row['label'] !== ''
            ? $row['label']
            : $this->label($u);

        return [
            'at' => $at,
            'kind' => $kind,
            'unit' => $u,
            'label' => $label,
            'detail' => $detail,
        ];
    }

    protected function label(string $unit): string
    {
        $u = preg_replace('/\.service$/i', '', $unit) ?? $unit;

        return is_string($u) ? $u : $unit;
    }
}
