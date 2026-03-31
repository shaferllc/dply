<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerSystemdServiceAuditEvent;
use App\Models\ServerSystemdServiceState;
use App\Services\Notifications\ServerSystemdNotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Parses inventory SSH output and replaces {@see ServerSystemdServiceState} rows; appends audit events from diffs.
 */
final class ServerSystemdInventoryRecorder
{
    public function __construct(
        protected ServerSystemdServiceSnapshotDiff $diff,
        protected ServerSystemdServicesCatalog $catalog,
    ) {}

    /**
     * @return list<array{unit: string, label: string, active: string, sub: string, ts: string, version: string, unit_file_state: string, main_pid: string, custom: bool, can_manage: bool}>
     */
    public function parseRows(Server $server, string $raw): array
    {
        $allowed = array_flip($this->catalog->allowedUnitsForServer($server));
        $rows = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim((string) $line);
            if ($line === '' || ! str_starts_with($line, 'DPLY_SVC_ROW:')) {
                continue;
            }
            $payload = substr($line, strlen('DPLY_SVC_ROW:'));
            $parts = explode('|', $payload);
            $n = count($parts);
            if ($n >= 7) {
                $u = (string) $parts[0];
                $act = (string) $parts[1];
                $sub = (string) $parts[2];
                $ts = (string) $parts[3];
                $ver = (string) $parts[4];
                $en = (string) $parts[5];
                $pid = (string) $parts[6];
            } elseif ($n >= 5) {
                $u = (string) $parts[0];
                $act = (string) $parts[1];
                $sub = (string) $parts[2];
                $ts = (string) $parts[3];
                $ver = (string) $parts[4];
                $en = '';
                $pid = '';
            } else {
                continue;
            }
            if ($u === '' || $u === '__none__' || ! str_ends_with($u, '.service')) {
                continue;
            }
            $label = preg_replace('/\.service$/i', '', $u) ?? $u;
            $rows[] = [
                'unit' => $u,
                'label' => is_string($label) ? $label : $u,
                'active' => $act,
                'sub' => $sub,
                'ts' => $ts,
                'version' => $ver,
                'unit_file_state' => $en,
                'main_pid' => $pid,
                'custom' => $this->isCustomUnit($server, $u),
                'can_manage' => isset($allowed[$u]),
            ];
        }
        usort($rows, fn (array $a, array $b) => strcmp($a['label'], $b['label']));

        return $rows;
    }

    public function persistInventoryFromRawOutput(Server $server, string $raw): void
    {
        $newRows = $this->parseRows($server, $raw);

        $oldRows = ServerSystemdServiceState::query()
            ->where('server_id', $server->id)
            ->orderBy('unit')
            ->get()
            ->map(fn (ServerSystemdServiceState $s) => [
                'unit' => $s->unit,
                'label' => $s->label,
                'active' => $s->active_state,
                'sub' => $s->sub_state,
                'ts' => (string) ($s->active_enter_ts ?? ''),
                'version' => $s->version,
                'unit_file_state' => (string) ($s->unit_file_state ?? ''),
                'main_pid' => (string) ($s->main_pid ?? ''),
            ])
            ->all();

        $diffEvents = $this->diff->diff($oldRows === [] ? null : $oldRows, $newRows);
        $maxAudit = max(10, (int) config('server_services.systemd_services_activity_max_events', 75));
        $now = now();

        DB::transaction(function () use ($server, $newRows, $diffEvents, $now): void {
            ServerSystemdServiceState::query()->where('server_id', $server->id)->delete();

            foreach ($newRows as $row) {
                ServerSystemdServiceState::query()->create([
                    'server_id' => $server->id,
                    'unit' => $row['unit'],
                    'label' => $row['label'],
                    'active_state' => $row['active'],
                    'sub_state' => $row['sub'],
                    'unit_file_state' => $row['unit_file_state'] ?? null,
                    'main_pid' => $row['main_pid'] ?? null,
                    'active_enter_ts' => $row['ts'] !== '' ? $row['ts'] : null,
                    'version' => $row['version'],
                    'is_custom' => $row['custom'],
                    'can_manage' => $row['can_manage'],
                    'captured_at' => $now,
                ]);
            }

            foreach ($diffEvents as $ev) {
                ServerSystemdServiceAuditEvent::query()->create([
                    'server_id' => $server->id,
                    'occurred_at' => Carbon::parse($ev['at']),
                    'kind' => $ev['kind'],
                    'unit' => $ev['unit'],
                    'label' => $ev['label'],
                    'detail' => $ev['detail'],
                ]);
            }
        });

        $this->pruneAuditEvents($server->id, $maxAudit);

        $dispatcher = app(ServerSystemdNotificationDispatcher::class);
        foreach ($diffEvents as $ev) {
            $dispatcher->notifyIfSubscribed($server, $ev);
        }
    }

    protected function pruneAuditEvents(string $serverId, int $keep): void
    {
        $keepIds = ServerSystemdServiceAuditEvent::query()
            ->where('server_id', $serverId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($keep)
            ->pluck('id');

        ServerSystemdServiceAuditEvent::query()
            ->where('server_id', $serverId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    protected function isCustomUnit(Server $server, string $normalizedUnit): bool
    {
        $meta = $server->meta ?? [];
        $list = $meta['custom_systemd_services'] ?? [];
        if (! is_array($list)) {
            return false;
        }
        foreach ($list as $item) {
            if (! is_string($item)) {
                continue;
            }
            if ($this->catalog->normalizeUnit($item) === $normalizedUnit) {
                return true;
            }
        }

        return false;
    }
}
