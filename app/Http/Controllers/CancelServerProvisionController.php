<?php

namespace App\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Non-Livewire escape hatch for cancelling a stuck provision. Posts from
 * a plain HTML form, so it keeps working when wire:click is broken (CSP,
 * Alpine error, stale snapshot, etc.). Mirrors the logic in
 * ProvisionJourney::markServerCancelled — kept here too so the two paths
 * don't drift if one is patched without the other.
 */
class CancelServerProvisionController extends Controller
{
    public function __invoke(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('update', $server);

        Log::info('CancelServerProvisionController invoked', [
            'server_id' => $server->id,
            'status' => $server->status,
            'setup_status' => $server->setup_status,
            'user_id' => $request->user()?->id,
        ]);

        $stillProvisioning = in_array($server->status, [
            Server::STATUS_PENDING,
            Server::STATUS_PROVISIONING,
        ], true) || $server->setup_status === Server::SETUP_STATUS_RUNNING;

        if (! $stillProvisioning) {
            return redirect()
                ->route('servers.journey', $server)
                ->with('status', 'Build is already finished — nothing to cancel.');
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        $meta['provision_cancelled'] = [
            'at' => now()->toIso8601String(),
            'by_user_id' => (string) ($request->user()?->id ?? ''),
            'via' => 'controller_form',
        ];

        $server->forceFill([
            'status' => Server::STATUS_ERROR,
            'setup_status' => $server->setup_status === Server::SETUP_STATUS_RUNNING
                ? Server::SETUP_STATUS_FAILED
                : $server->setup_status,
            'meta' => $meta,
        ])->save();

        return redirect()
            ->route('servers.journey', $server)
            ->with('status', 'Build cancelled.');
    }
}
