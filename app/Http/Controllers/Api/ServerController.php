<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\SshConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    /**
     * List servers for the token's organization.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');

        $servers = Server::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'ip_address', 'provider', 'created_at']);

        return response()->json([
            'data' => $servers->map(fn (Server $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'status' => $s->status,
                'ip_address' => $s->ip_address,
                'provider' => $s->provider->value,
                'created_at' => $s->created_at?->toIso8601String(),
            ]),
        ]);
    }

    // The `deploy()` action was removed alongside the legacy
    // `Server.deploy_command` column. Server-level commands are now
    // ServerRecipe rows runnable from the /run UI; programmatic
    // execution can use `runCommand` below.

    /**
     * Run an arbitrary command on the server.
     */
    public function runCommand(Request $request, Server $server): JsonResponse
    {
        $organization = $request->attributes->get('api_organization');

        if ($server->organization_id !== $organization->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'command' => 'required|string|max:1000',
        ]);

        try {
            $ssh = new SshConnection($server);
            $output = $ssh->exec($validated['command']);

            return response()->json([
                'message' => 'Command completed.',
                'output' => $output,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Command failed.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
