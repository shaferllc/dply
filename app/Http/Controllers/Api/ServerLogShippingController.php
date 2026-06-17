<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Servers\ManageServerLogShipping;
use App\Exceptions\LogShippingException;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST surface for the dply Logs add-on (per-server edge Vector agent). Thin
 * wrapper over {@see ManageServerLogShipping} — the same action the Livewire
 * workspace and MCP tools use. Drives the dply-cli `servers:log-shipping`
 * command. See docs/SERVER_LOGS_ADDON.md.
 */
class ServerLogShippingController extends Controller
{
    public function show(Request $request, Server $server, ManageServerLogShipping $action): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));

        return response()->json(['data' => $action->status($server)]);
    }

    public function enable(Request $request, Server $server, ManageServerLogShipping $action): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));

        $validated = $request->validate([
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['boolean'],
        ]);

        return $this->run(fn () => $action->enable($server, $validated['sources'] ?? null), $server, $action);
    }

    public function resync(Request $request, Server $server, ManageServerLogShipping $action): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));

        return $this->run(fn () => $action->resync($server), $server, $action);
    }

    public function disable(Request $request, Server $server, ManageServerLogShipping $action): JsonResponse
    {
        $this->assertServerOrg($server, $this->organization($request));

        $action->disable($server);

        return response()->json(['data' => $action->status($server->refresh())]);
    }

    /**
     * Run a mutating action, translating a refusal into a 422 and otherwise
     * returning the fresh status so callers see the new state in one round-trip.
     *
     * @param  callable(): mixed  $callable
     */
    private function run(callable $callable, Server $server, ManageServerLogShipping $action): JsonResponse
    {
        try {
            $callable();
        } catch (LogShippingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $action->status($server->refresh())]);
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('api_organization');
    }

    private function assertServerOrg(Server $server, Organization $organization): void
    {
        abort_if($server->organization_id !== $organization->id, 403);
    }
}
