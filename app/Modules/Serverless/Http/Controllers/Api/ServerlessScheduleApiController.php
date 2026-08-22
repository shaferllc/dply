<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Http\Controllers\Api;

use App\Modules\Serverless\Models\FunctionInvocation;
use App\Modules\Serverless\Services\InvokeFunctionTick;
use App\Modules\Serverless\Services\ServerlessBackgroundTasks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The workspace **Schedule** tab over HTTP: the scheduler switch and the
 * firing history behind it.
 *
 * This is dply's own minute-cadence tick — the one that runs the app's
 * scheduler. The *host's* cron triggers are a different thing and live on
 * {@see ServerlessPlatformApiController::schedules()} (`platform --schedules`).
 *
 * `tick` sits behind `serverless.invoke`: it runs the customer's code and
 * bills an invocation, like any other invoke.
 */
class ServerlessScheduleApiController extends ServerlessApiController
{
    public function __construct(private readonly ServerlessBackgroundTasks $tasks) {}

    /** Scheduler state plus a page of firing history (`?limit`, `?failed=1`). */
    public function show(Request $request, string $site): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        $query = FunctionInvocation::query()
            ->where('site_id', $found->id)
            ->where('source', FunctionInvocation::SOURCE_TICK)
            ->where('task', 'schedule')
            ->orderByDesc('created_at');

        $total = (clone $query)->count();

        if ($request->boolean('failed')) {
            $query->where('success', false);
        }

        $ticks = $query->limit($this->limit($request, 20, 200))->get();

        return response()->json([
            'data' => [
                'enabled' => $this->tasks->enabled($found, 'schedule'),
                'total_ticks' => $total,
                'ticks' => $ticks->map(fn (FunctionInvocation $tick): array => $tick->toTickEntry())->all(),
            ],
        ]);
    }

    public function update(Request $request, string $site): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $this->tasks->setEnabled($found, 'schedule', (bool) $data['enabled']);

        return response()->json(['data' => ['enabled' => (bool) $data['enabled']]]);
    }

    /** Fire one scheduler tick now — the tab's "Tick now" button. */
    public function tick(Request $request, string $site, InvokeFunctionTick $tick): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        $invocation = $tick->tickSite($found, 'schedule');

        if ($invocation === null) {
            return response()->json([
                'message' => 'Cannot tick — the function has no command secret yet. Deploy it first.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'id' => $invocation->id,
                'success' => (bool) $invocation->success,
                'status_code' => $invocation->status_code,
                'duration_ms' => (int) $invocation->duration_ms,
                'excerpt' => $invocation->result_excerpt,
            ],
        ]);
    }
}
