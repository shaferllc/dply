<?php

declare(strict_types=1);

namespace App\Livewire\Backups;

use App\Livewire\Backups\Concerns\RunsBackupSchedules;
use App\Livewire\Backups\Concerns\SummarisesBackupRuns;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Servers\WorkspaceSnapshots;
use App\Models\BackupSchedule;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerImage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fleet roll-up of full-disk provider images — the org-wide view that the
 * per-server {@see WorkspaceSnapshots} hub cannot give you: which servers are
 * covered, which are capable and uncovered, and every image in the workspace
 * newest-first.
 *
 * Taking and deleting an image stays on the server workspace, so this tab links
 * out rather than growing its own capture button. Recurring image policies
 * ({@see BackupSchedule::TARGET_SERVER_IMAGE}) are an M2 engine feature — the
 * rows are surfaced here read-only the moment they exist rather than being
 * invisible until the engine lands. See docs/adr/backups-as-a-product.md,
 * decisions 2 and 15.
 */
#[Layout('layouts.app')]
class Snapshots extends Component
{
    use DispatchesToastNotifications;
    use RunsBackupSchedules;
    use SummarisesBackupRuns;

    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        if (! $org instanceof Organization) {
            abort(403, 'Select an organization first.');
        }

        if (! Feature::for($org)->active('workspace.backups')) {
            return view('livewire.backups.snapshots', ['featureActive' => false]);
        }

        $servers = $org->servers()->orderBy('name')->get();
        $serverIds = $servers->pluck('id');

        // Capability is per provider: DO / Hetzner / Vultr / Linode wrap an image
        // API, everything else (and every Custom box) cannot be imaged at all —
        // so it must not count against coverage, or the number becomes a scold
        // nobody can act on.
        $capable = $servers->filter(fn (Server $server): bool => $server->provider->supportsImageSnapshots());

        $images = ServerImage::query()
            ->whereIn('server_id', $serverIds)
            ->with('server')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $imagedServerIds = ServerImage::query()
            ->whereIn('server_id', $capable->pluck('id'))
            ->where('status', ServerImage::STATUS_COMPLETED)
            ->distinct()
            ->pluck('server_id');

        $totalBytes = ServerImage::query()
            ->whereIn('server_id', $serverIds)
            ->where('status', ServerImage::STATUS_COMPLETED)
            ->sum('bytes');

        // The newest image per server, whatever its status — the row needs to
        // say "failed 2h ago" as readily as "ready 2h ago". Bounded rather than
        // one query per server.
        $latestByServer = ServerImage::query()
            ->whereIn('server_id', $serverIds)
            ->orderByDesc('created_at')
            ->limit(300)
            ->get()
            ->unique('server_id')
            ->keyBy('server_id');

        $imageCounts = ServerImage::query()
            ->whereIn('server_id', $serverIds)
            ->selectRaw('server_id, count(*) as total')
            ->groupBy('server_id')
            ->pluck('total', 'server_id');

        // Recurring image policies are M2, so this is normally empty. Surfacing
        // it anyway means the rows are never invisible the way site-file
        // schedules were on the Files tab before they were wired up.
        $schedules = BackupSchedule::query()
            ->where('target_type', BackupSchedule::TARGET_SERVER_IMAGE)
            ->whereIn('server_id', $serverIds)
            ->with(['server', 'backupConfiguration'])
            ->orderByDesc('is_active')
            ->orderByDesc('last_run_at')
            ->get();

        return view('livewire.backups.snapshots', [
            'featureActive' => true,
            'organization' => $org,
            // Capable servers first: the rows with nothing actionable on them
            // should not sit between the ones an operator came here to act on.
            // sortBy is stable, so alphabetical order survives within each group.
            'servers' => $servers
                ->sortByDesc(fn (Server $server): bool => $server->provider->supportsImageSnapshots())
                ->values(),
            'images' => $images,
            'latestByServer' => $latestByServer,
            'imageCounts' => $imageCounts,
            'imagedServerIds' => $imagedServerIds,
            'schedulesByTarget' => $schedules->groupBy('target_id'),
            'orphanSchedules' => $schedules
                ->reject(fn (BackupSchedule $schedule) => $serverIds->contains($schedule->target_id))
                ->values(),
            'nextRuns' => $this->nextRuns($schedules),
            'trends' => $this->recentSizes(
                ServerImage::query()->whereIn('server_id', $serverIds),
                'server_id',
            ),
            'activity' => $this->dailyActivity(
                ServerImage::query()->whereIn('server_id', $serverIds),
            ),
            // Capable but never imaged — the actionable list, used for the empty
            // state's call to action.
            'uncovered' => $capable->whereNotIn('id', $imagedServerIds)->values(),
            'metrics' => [
                'servers' => $servers->count(),
                'capable' => $capable->count(),
                'incapable' => $servers->count() - $capable->count(),
                'imaged' => $imagedServerIds->count(),
                'images' => (int) $imageCounts->sum(),
                'storage' => Number::fileSize((int) $totalBytes),
                'coverage' => $capable->count() > 0
                    ? (int) round($imagedServerIds->count() / $capable->count() * 100)
                    : 0,
            ],
        ]);
    }
}
