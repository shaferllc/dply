<?php

namespace App\Services\Servers;

/**
 * Compares two systemd inventory snapshots (running-service rows) and emits high-level events.
 * Used to populate {@see ManagesServerSystemdServices} activity log without a guest-side daemon.
 */
final class ServerSystemdServiceSnapshotDiff
{
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
                $events[] = $this->makeEvent($now, 'restarted', $new, __('ActiveEnterTimestamp changed (likely restart).'));

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

    /**
     * @param  list<array<string, mixed>>  $rows
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
     * @param  array<string, mixed>  $row
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
