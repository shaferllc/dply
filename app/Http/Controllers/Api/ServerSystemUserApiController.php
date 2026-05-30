<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreateServerSystemUserJob;
use App\Jobs\DeleteServerSystemUserJob;
use App\Jobs\SyncServerSystemUsersJob;
use App\Jobs\UpdateServerSystemUserJob;
use App\Models\Server;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServerSystemUserApiController extends Controller
{
    public function index(Request $request, Server $server, ServerSystemUserService $service): JsonResponse
    {
        if ($response = $this->authorizeServer($request, $server)) {
            return $response;
        }

        $rows = $service->storedSystemUsersWithMetadata($server);

        return response()->json([
            'data' => array_map(fn (array $row): array => $this->serializeRow($row), $rows),
        ]);
    }

    public function sync(Request $request, Server $server): JsonResponse
    {
        if ($response = $this->authorizeServer($request, $server)) {
            return $response;
        }

        if ($response = $this->requireReadyServer($server)) {
            return $response;
        }

        SyncServerSystemUsersJob::dispatch($server->id, auth()->id());

        return response()->json([
            'message' => 'System user sync queued.',
            'server_id' => $server->id,
        ], 202);
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        if ($response = $this->authorizeServer($request, $server)) {
            return $response;
        }

        if ($response = $this->requireReadyServer($server)) {
            return $response;
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'sudo' => ['sometimes', 'boolean'],
            'shell' => ['sometimes', Rule::in(['/bin/bash', '/bin/sh', '/usr/sbin/nologin'])],
            'web_group' => ['sometimes', 'boolean'],
        ]);

        $extraGroups = [];
        if (filter_var($validated['web_group'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            $webGroup = trim((string) config('site_settings.vm_site_file_web_group', 'www-data'));
            if ($webGroup !== '') {
                $extraGroups[] = $webGroup;
            }
        }

        CreateServerSystemUserJob::dispatch(
            $server->id,
            $validated['username'],
            filter_var($validated['sudo'] ?? false, FILTER_VALIDATE_BOOLEAN),
            auth()->id(),
            $validated['shell'] ?? '/bin/bash',
            $extraGroups,
        );

        return response()->json([
            'message' => 'System user creation queued.',
            'username' => $validated['username'],
            'server_id' => $server->id,
        ], 202);
    }

    public function update(Request $request, Server $server, string $username): JsonResponse
    {
        if ($response = $this->authorizeServer($request, $server)) {
            return $response;
        }

        if ($response = $this->requireReadyServer($server)) {
            return $response;
        }

        $validated = $request->validate([
            'shell' => ['sometimes', Rule::in(['/bin/bash', '/bin/sh', '/usr/sbin/nologin'])],
            'sudo' => ['sometimes', 'boolean'],
            'web_group' => ['sometimes', 'boolean'],
        ]);

        if (! array_key_exists('shell', $validated)
            && ! array_key_exists('sudo', $validated)
            && ! array_key_exists('web_group', $validated)) {
            return response()->json([
                'message' => 'Provide at least one of: shell, sudo, web_group.',
            ], 422);
        }

        UpdateServerSystemUserJob::dispatch(
            $server->id,
            $username,
            $validated['shell'] ?? null,
            array_key_exists('sudo', $validated) ? filter_var($validated['sudo'], FILTER_VALIDATE_BOOLEAN) : null,
            array_key_exists('web_group', $validated) ? filter_var($validated['web_group'], FILTER_VALIDATE_BOOLEAN) : null,
            auth()->id(),
        );

        return response()->json([
            'message' => 'System user update queued.',
            'username' => $username,
            'server_id' => $server->id,
        ], 202);
    }

    public function destroy(Request $request, Server $server, string $username): JsonResponse
    {
        if ($response = $this->authorizeServer($request, $server)) {
            return $response;
        }

        if ($response = $this->requireReadyServer($server)) {
            return $response;
        }

        DeleteServerSystemUserJob::dispatch($server->id, $username, auth()->id());

        return response()->json([
            'message' => 'System user removal queued.',
            'username' => $username,
            'server_id' => $server->id,
        ], 202);
    }

    private function authorizeServer(Request $request, Server $server): ?JsonResponse
    {
        $organization = $request->attributes->get('api_organization');
        if ($server->organization_id !== $organization->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }

    private function requireReadyServer(Server $server): ?JsonResponse
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return response()->json([
                'message' => 'Server must be ready with SSH before managing system users.',
            ], 422);
        }

        return null;
    }

    /**
     * @param  array{username: string, site_count: int, is_protected: bool, is_orphan: bool, uid: int|null, home: string, shell: string, groups: list<string>, sites: list<array{id: string, name: string}>}  $row
     * @return array<string, mixed>
     */
    private function serializeRow(array $row): array
    {
        return [
            'username' => $row['username'],
            'uid' => $row['uid'],
            'home' => $row['home'],
            'shell' => $row['shell'],
            'groups' => $row['groups'],
            'site_count' => $row['site_count'],
            'is_protected' => $row['is_protected'],
            'is_orphan' => $row['is_orphan'],
            'sites' => $row['sites'],
        ];
    }
}
