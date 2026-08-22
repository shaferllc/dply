<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Http\Controllers\Api;

use App\Models\Site;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Modules\Serverless\Services\InvokeFunctionTick;
use App\Modules\Serverless\Services\ServerlessBackgroundTasks;
use App\Modules\Serverless\Services\SiteWorkerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The workspace **Workers** tab over HTTP.
 *
 * Two things live here, the same two the tab carries: the queue engine — one
 * boolean driving the minute-cadence tick, shared with Schedule's scheduler
 * switch via {@see ServerlessBackgroundTasks} — and the named worker
 * definitions from {@see SiteWorkerRegistry}. The page reads and writes
 * through the same two services, so the CLI cannot drift from it.
 *
 * `tick` sits behind `serverless.invoke` rather than `serverless.write`: it
 * runs the customer's code and bills an invocation, exactly like
 * {@see ServerlessPlatformApiController::invoke()}.
 */
class ServerlessWorkersApiController extends ServerlessApiController
{
    public function __construct(
        private readonly SiteWorkerRegistry $registry,
        private readonly ServerlessBackgroundTasks $tasks,
    ) {}

    public function index(Request $request, string $site): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->state($found)]);
    }

    /** Flip the queue engine — the tab's "process queue jobs" switch. */
    public function updateEngine(Request $request, string $site): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $this->tasks->setEnabled($found, 'queue', (bool) $data['enabled']);

        return response()->json(['data' => $this->state($found)]);
    }

    public function store(Request $request, string $site): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'command' => ['required', 'string', 'max:255'],
            'concurrency' => ['nullable', 'integer', 'min:1', 'max:50'],
            'restart_policy' => ['nullable', Rule::in(SiteWorkerRegistry::RESTART_POLICIES)],
            'enabled' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'data' => $this->registry->add($found, array_filter($data, fn ($value): bool => $value !== null)),
        ], 201);
    }

    /** Patch one worker — anything absent from the body is left alone. */
    public function update(Request $request, string $site, string $worker): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'command' => ['sometimes', 'string', 'max:255'],
            'concurrency' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'restart_policy' => ['sometimes', Rule::in(SiteWorkerRegistry::RESTART_POLICIES)],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $updated = $this->registry->update($found, $worker, $data);

        if ($updated === null) {
            return $this->notFound('Worker not found.');
        }

        return response()->json(['data' => $updated]);
    }

    public function destroy(Request $request, string $site, string $worker): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        if (! $this->registry->remove($found, $worker)) {
            return $this->notFound('Worker not found.');
        }

        return response()->json(['data' => ['removed' => true]]);
    }

    /** Fire one queue tick now — the tab's "Tick now" button. */
    public function tick(Request $request, string $site, InvokeFunctionTick $tick): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);

        if ($found === null) {
            return $this->notFound();
        }

        $invocation = $tick->tickSite($found, 'queue');

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

    /**
     * Engine state, the worker list with each worker's derived status, and the
     * last queue tick — the same three things the tab shows above its table.
     *
     * @return array<string, mixed>
     */
    private function state(Site $site): array
    {
        $engineOn = $this->tasks->enabled($site, 'queue');
        $latest = FunctionInvocation::query()
            ->where('site_id', $site->id)
            ->where('source', FunctionInvocation::SOURCE_TICK)
            ->where('task', 'queue')
            ->latest('created_at')
            ->first();

        $lastStatus = $latest === null ? null : ($latest->success ? 'ok' : 'failed');

        return [
            'engine_enabled' => $engineOn,
            'last_tick' => $latest === null ? null : [
                'at' => $latest->created_at?->toIso8601String(),
                'status' => $lastStatus,
                'status_code' => $latest->status_code,
                'duration_ms' => (int) $latest->duration_ms,
            ],
            'workers' => array_map(function (array $worker) use ($engineOn, $lastStatus): array {
                [$state, $label] = $this->registry->status($worker, $engineOn, $lastStatus);

                return [...$worker, 'status' => $state, 'status_label' => $label];
            }, $this->registry->all($site)),
        ];
    }
}
