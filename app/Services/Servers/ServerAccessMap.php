<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerSystemUser;

/**
 * Node-link "who → as whom → owns what" access map for the SSH access workspace.
 *
 * Three columns:
 *   1. Key sources (principals) — profile/org/team/session/ephemeral/server-local
 *   2. Linux accounts (the spine) — the target_linux_user each key authenticates as,
 *      cross-referenced to the /etc/passwd snapshot ({@see ServerSystemUser}) for
 *      login/protected/exists metadata
 *   3. Workloads — what depends on each account existing (sites, workers, cron)
 *
 * Edges connect source→account (a key of that source targets that account) and
 * account→workload (that account owns N of that workload type). Layout coordinates
 * are computed here so the blade is a dumb renderer and the SVG edge layer survives
 * Livewire morphs without JS measurement.
 */
final class ServerAccessMap
{
    /** Node card height in px (fixed so y-coordinates are deterministic). */
    private const ROW_H = 58;

    /** Vertical gap between node cards in px. */
    private const ROW_GAP = 18;

    /** Top padding inside the map container in px. */
    private const PAD_TOP = 10;

    /** SVG x anchors in 0..100 viewBox units (preserveAspectRatio="none"). */
    private const COL_SOURCES_RIGHT = 30.0;

    private const COL_ACCOUNTS_LEFT = 37.0;

    private const COL_ACCOUNTS_RIGHT = 63.0;

    private const COL_WORKLOADS_LEFT = 70.0;

    public function __construct(
        private ServerSystemUserDeletionPolicy $deletionPolicy,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $reportRows  rows from {@see ServerSshAccessGraph::forServer()}
     * @return array{
     *     has_data: bool,
     *     height: int,
     *     columns: array{sources: string, accounts: string, workloads: string},
     *     sources: list<array<string, mixed>>,
     *     accounts: list<array<string, mixed>>,
     *     workloads: list<array<string, mixed>>,
     *     edges: list<array<string, mixed>>,
     * }
     */
    public function build(Server $server, array $reportRows): array
    {
        $siteCounts = $this->deletionPolicy->siteCountsByUsername($server);
        $workerCounts = $this->deletionPolicy->workerCountsByUsername($server);
        $cronCounts = $this->deletionPolicy->cronCountsByUsername($server);

        /** @var array<string, ServerSystemUser> $passwd */
        $passwd = $server->systemUsers()->get()
            ->keyBy(fn (ServerSystemUser $u): string => strtolower(trim((string) $u->username)))
            ->all();

        // The passwd snapshot only holds regular accounts (UID >= 1000). System
        // accounts that legitimately run workloads (root for cron, www-data for
        // workers) live outside it, and an un-synced server has no snapshot at
        // all — so we only flag an account as "missing" when we have a snapshot
        // to compare against and the name isn't a known system account.
        $snapshotKnown = $passwd !== [];
        $knownSystem = ['root', 'www-data', 'nobody'];

        // Tally key sources and the (source, account) pairs that connect them.
        $sourceCounts = [];                 // source => key count
        $accountUsers = [];                 // account user => key count
        $pairCounts = [];                   // "source|account" => key count
        foreach ($reportRows as $row) {
            $source = (string) ($row['source'] ?? 'server-local');
            $account = strtolower(trim((string) ($row['target_linux_user'] ?? '')));
            if ($account === '') {
                continue;
            }

            $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
            $accountUsers[$account] = ($accountUsers[$account] ?? 0) + 1;
            $pairCounts[$source.'|'.$account] = ($pairCounts[$source.'|'.$account] ?? 0) + 1;
        }

        // Accounts also referenced by a workload but with no authorized key still
        // belong on the map (e.g. a worker pinned to an unused service account).
        foreach (array_keys($siteCounts + $workerCounts + $cronCounts) as $account) {
            $accountUsers[$account] ??= 0;
        }

        if ($accountUsers === []) {
            return $this->empty();
        }

        ksort($sourceCounts);
        ksort($accountUsers);

        $sshUser = strtolower(trim((string) $server->ssh_user));

        $sources = [];
        $sourceY = [];
        $i = 0;
        foreach ($sourceCounts as $source => $count) {
            $id = 'src:'.$source;
            $cy = $this->centerY($i);
            $sourceY[$source] = $cy;
            $sources[] = [
                'id' => $id,
                'kind' => $source,
                'label' => $this->sourceLabel($source),
                'count' => $count,
                'top' => $this->topY($i),
            ];
            $i++;
        }

        $accounts = [];
        $accountY = [];
        $i = 0;
        foreach ($accountUsers as $user => $keyCount) {
            $id = 'acct:'.$user;
            $cy = $this->centerY($i);
            $accountY[$user] = $cy;
            $model = $passwd[$user] ?? null;
            $isProtected = $this->deletionPolicy->isProtected($server, $user);
            $known = $model !== null || $isProtected || in_array($user, $knownSystem, true);

            $accounts[] = [
                'id' => $id,
                'user' => $user,
                'key_count' => $keyCount,
                'exists' => $snapshotKnown ? $known : true,
                'uid' => $model?->uid,
                'is_login' => $sshUser !== '' && $user === $sshUser,
                'is_protected' => $isProtected,
                'sites' => $siteCounts[$user] ?? 0,
                'workers' => $workerCounts[$user] ?? 0,
                'crons' => $cronCounts[$user] ?? 0,
                'top' => $this->topY($i),
            ];
            $i++;
        }

        // Workload column: only the types actually present on this server.
        $workloadDefs = [
            'sites' => ['label' => __('Sites'), 'total' => array_sum($siteCounts)],
            'workers' => ['label' => __('Workers'), 'total' => array_sum($workerCounts)],
            'crons' => ['label' => __('Cron jobs'), 'total' => array_sum($cronCounts)],
        ];
        $workloads = [];
        $workloadY = [];
        $i = 0;
        foreach ($workloadDefs as $kind => $def) {
            if ($def['total'] <= 0) {
                continue;
            }
            $cy = $this->centerY($i);
            $workloadY[$kind] = $cy;
            $workloads[] = [
                'id' => 'wl:'.$kind,
                'kind' => $kind,
                'label' => $def['label'],
                'total' => $def['total'],
                'top' => $this->topY($i),
            ];
            $i++;
        }

        $edges = [];
        foreach ($pairCounts as $pair => $count) {
            [$source, $account] = explode('|', $pair, 2);
            if (! isset($sourceY[$source], $accountY[$account])) {
                continue;
            }
            $edges[] = [
                'from' => 'src:'.$source,
                'to' => 'acct:'.$account,
                'count' => $count,
                'x1' => self::COL_SOURCES_RIGHT,
                'y1' => $sourceY[$source],
                'x2' => self::COL_ACCOUNTS_LEFT,
                'y2' => $accountY[$account],
            ];
        }
        foreach ($accounts as $account) {
            foreach (['sites', 'workers', 'crons'] as $kind) {
                if (($account[$kind] ?? 0) <= 0 || ! isset($workloadY[$kind])) {
                    continue;
                }
                $edges[] = [
                    'from' => $account['id'],
                    'to' => 'wl:'.$kind,
                    'count' => $account[$kind],
                    'x1' => self::COL_ACCOUNTS_RIGHT,
                    'y1' => $accountY[$account['user']],
                    'x2' => self::COL_WORKLOADS_LEFT,
                    'y2' => $workloadY[$kind],
                ];
            }
        }

        $rows = max(count($sources), count($accounts), count($workloads), 1);

        return [
            'has_data' => true,
            'height' => self::PAD_TOP + $rows * (self::ROW_H + self::ROW_GAP),
            'columns' => [
                'sources' => __('Key sources'),
                'accounts' => __('Linux accounts'),
                'workloads' => __('Workloads'),
            ],
            'sources' => $sources,
            'accounts' => $accounts,
            'workloads' => $workloads,
            'edges' => $edges,
        ];
    }

    /**
     * @return array{has_data: bool, height: int, columns: array{sources: string, accounts: string, workloads: string}, sources: list<array<string, mixed>>, accounts: list<array<string, mixed>>, workloads: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function empty(): array
    {
        return [
            'has_data' => false,
            'height' => 0,
            'columns' => [
                'sources' => __('Key sources'),
                'accounts' => __('Linux accounts'),
                'workloads' => __('Workloads'),
            ],
            'sources' => [],
            'accounts' => [],
            'workloads' => [],
            'edges' => [],
        ];
    }

    private function topY(int $index): int
    {
        return self::PAD_TOP + $index * (self::ROW_H + self::ROW_GAP);
    }

    private function centerY(int $index): float
    {
        return $this->topY($index) + self::ROW_H / 2;
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'profile' => __('Profile keys'),
            'organization' => __('Organization keys'),
            'team' => __('Team keys'),
            'session' => __('Temporary sessions'),
            'ephemeral' => __('Deploy (ephemeral)'),
            default => __('Server-local keys'),
        };
    }
}
