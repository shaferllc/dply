<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AuthorizesHostExecution;
use App\Http\Controllers\Controller;
use App\Jobs\RunServerCommandJob;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Modules\Insights\Services\OrganizationInsightsMetricsService;
use App\Support\Servers\ServerIndexAssembler;
use App\Support\Sites\SiteSyncPeers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    use AuthorizesHostExecution;

    private const COMMAND_RUN_KIND = 'server:run-command';

    /**
     * List servers for the token's organization (full fleet-card payload).
     */
    public function index(Request $request, OrganizationInsightsMetricsService $insightsMetrics): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');

        // Machines only, same as the /servers UI. Edge apps, function
        // namespaces and Cloud containers are placeholder host rows belonging
        // to /edge and /cloud; shipping them here put "servers" in
        // a consumer's fleet that can't be SSHed into, sized, or provisioned —
        // and the local production mirror then materialized them as if they
        // were VMs.
        $servers = Server::query()
            ->onlyMachineHosts()
            ->where('organization_id', $organization->id)
            ->with([
                'workspace:id,name',
                'organization:id,name',
                'team:id,name',
                'sites',
                'databaseEngines',
                'cacheServices',
                // Names only — the assembler exposes them so a consumer can
                // reproduce the Databases count/list without a second call.
                'serverDatabases:id,server_id,name',
            ])
            ->withCount('sites')
            ->orderBy('name')
            ->get();

        $latestSnapshots = collect();
        if ($servers->isNotEmpty()) {
            $serverIds = $servers->pluck('id')->all();
            $latestSnapshots = ServerMetricSnapshot::query()
                ->whereIn('server_id', $serverIds)
                ->whereIn('id', function ($q) use ($serverIds): void {
                    $q->from('server_metric_snapshots')
                        ->selectRaw('MAX(id)')
                        ->whereIn('server_id', $serverIds)
                        ->groupBy('server_id');
                })
                ->get(['id', 'server_id', 'captured_at', 'payload'])
                ->keyBy('server_id');
        }

        $insightRollup = $servers->isNotEmpty()
            ? $insightsMetrics->perServerRollup($servers->pluck('id'))
            : collect();

        $relatedMap = ServerIndexAssembler::relatedServersMap($servers, $servers);

        $repoCounts = Site::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('git_repository_url')
            ->where('git_repository_url', '!=', '')
            ->pluck('git_repository_url')
            ->groupBy(fn (string $repo): string => SiteSyncPeers::canonicalRepo($repo))
            ->map->count();

        $deployMeta = [];
        foreach ($servers as $server) {
            $deployable = $server->sites->filter(function (Site $site) use ($server): bool {
                $site->setRelation('server', $server);

                return filled($site->git_repository_url)
                    && $server->status === Server::STATUS_READY
                    && $server->setup_status === Server::SETUP_STATUS_DONE;
            })->values();

            if ($deployable->isEmpty()) {
                continue;
            }

            $anchor = $deployable->first();
            $repo = SiteSyncPeers::canonicalRepo((string) $anchor->git_repository_url);
            $deployMeta[$server->id] = [
                'sync_count' => $repo !== ''
                    ? (int) ($repoCounts[$repo] ?? 1)
                    : (int) $server->sites->count(),
                'anchor_site_id' => (string) $anchor->id,
            ];
        }

        return response()->json([
            'data' => $servers->map(function (Server $s) use ($latestSnapshots, $insightRollup, $relatedMap, $deployMeta) {
                $insights = $insightRollup[$s->id] ?? ['open' => 0, 'worst' => null];
                $meta = $deployMeta[$s->id] ?? null;

                return ServerIndexAssembler::toArray(
                    $s,
                    $latestSnapshots->get($s->id),
                    (int) $insights['open'],
                    isset($insights['worst']) ? (string) $insights['worst'] : null,
                    $relatedMap[$s->id] ?? [],
                    $meta !== null,
                    deploySyncCount: (int) ($meta['sync_count'] ?? 0),
                    deployAnchorSiteId: isset($meta['anchor_site_id']) ? (string) $meta['anchor_site_id'] : null,
                );
            })->values(),
        ]);
    }

    /**
     * Queue an arbitrary command on the server and report the run.
     *
     * The work happens in {@see RunServerCommandJob}, not here: SSH inside a
     * request is forbidden by house rule and caps a command at the web request
     * timeout. For the common case — a fast command — this still returns the
     * output inline, by waiting on the run record rather than on a socket, so
     * an existing caller sees the response shape it always did.
     */
    public function runCommand(Request $request, Server $server): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');

        if ($server->organization_id !== $organization->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // The token's ability is not the user's authorization: an org member can
        // hold a commands.run token for a server their workspace role does not
        // let them touch. Both have to pass.
        if ($request->user()?->cannot('update', $server) ?? true) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'command' => 'required|string|max:1000',
            'wait_seconds' => 'sometimes|integer|min:0|max:20',
        ]);

        $action = ConsoleAction::query()->create([
            'subject_type' => $server->getMorphClass(),
            'subject_id' => $server->getKey(),
            'kind' => self::COMMAND_RUN_KIND,
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => mb_substr($validated['command'], 0, 200),
            // Who ran it: the old endpoint recorded nothing at all.
            'user_id' => $request->user()?->getKey(),
        ]);

        RunServerCommandJob::dispatch($action->id, (string) $server->getKey(), $validated['command']);

        $settled = $this->awaitConsoleAction($action->id, (int) ($validated['wait_seconds'] ?? 10));

        return response()->json($this->commandRunPayload($settled ?? $action->refresh()));
    }

    /**
     * Poll one command run. Scoped to the server so a run id from another
     * organization cannot be read back through a server this token owns.
     */
    public function commandRun(Request $request, Server $server, string $action): JsonResponse
    {
        $this->authorizeServerRead($request, $server);

        $row = ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->getKey())
            ->where('kind', self::COMMAND_RUN_KIND)
            ->find($action);

        if ($row === null) {
            return response()->json(['message' => 'No command run with that id on this server.'], 404);
        }

        return response()->json($this->commandRunPayload($row));
    }

    /**
     * Wait for the worker by polling the row rather than holding a socket open.
     * Null when it is still going at the deadline — the caller polls from there,
     * and the command keeps running either way.
     */
    private function awaitConsoleAction(string $id, int $seconds): ?ConsoleAction
    {
        $deadline = microtime(true) + $seconds;

        while (true) {
            $row = ConsoleAction::query()->find($id);
            if ($row !== null && ! $row->isInFlight()) {
                return $row;
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(250_000);
        }
    }

    /**
     * `output` and `message` keep the shape the synchronous endpoint returned,
     * so a caller reading them still works; `run_id` and `status` are what a
     * caller polls with.
     *
     * @return array<string, mixed>
     */
    private function commandRunPayload(ConsoleAction $action): array
    {
        $lines = [];
        foreach ((array) ($action->output['lines'] ?? []) as $line) {
            if (is_array($line) && isset($line['line']) && ($line['source'] ?? null) === 'command') {
                $lines[] = (string) $line['line'];
            }
        }

        $exitCode = null;
        if (is_string($action->error) && preg_match('/^exit (\d+)$/', $action->error, $m) === 1) {
            $exitCode = (int) $m[1];
        } elseif ($action->status === ConsoleAction::STATUS_COMPLETED) {
            $exitCode = 0;
        }

        return [
            'run_id' => $action->id,
            'status' => $action->status,
            'exit_code' => $exitCode,
            'output' => implode("\n", $lines),
            'message' => match ($action->status) {
                ConsoleAction::STATUS_COMPLETED => 'Command completed.',
                ConsoleAction::STATUS_FAILED => 'Command failed.',
                default => 'Command queued.',
            },
            // A transport failure is reported without the raw exception text,
            // which used to go straight to the caller.
            'error' => $action->status === ConsoleAction::STATUS_FAILED
                ? 'The command could not be run on this server.'
                : null,
        ];
    }
}
