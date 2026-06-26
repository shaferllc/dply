<?php

namespace App\Services\Servers;

use App\Exceptions\ServerCommandNotPermittedException;
use App\Jobs\RunServerCommandJob;
use App\Models\Server;
use App\Models\ServerCommandRun;
use App\Models\ServerRecipe;
use App\Models\User;

/**
 * Orchestrates server-scoped shell runs for the Run page.
 *
 * Keeps the security-sensitive bits (RBAC gate, queued dispatch) out of
 * the Livewire component and in one testable place. Mirrors the shape of
 * {@see \App\Modules\RemoteCli\Services\RemoteCli::run()} but for arbitrary
 * server commands, which can't be statically risk-classified — so the gate
 * is role-based (Deployers blocked) and the audit trail is the persisted
 * {@see ServerCommandRun} row plus an AuditLog entry written by the job.
 */
class ServerCommandRunner
{
    /**
     * Persist a queued run and dispatch the worker. Returns the row so the
     * caller can poll it for streamed output.
     *
     * @param  string  $source  One of ServerCommandRun::SOURCE_*
     *
     * @throws ServerCommandNotPermittedException When the actor may not run shell here.
     */
    public function queue(
        Server $server,
        User $actor,
        string $displayCommand,
        string $remoteCommand,
        string $source = ServerCommandRun::SOURCE_ADHOC,
        ?ServerRecipe $recipe = null,
        ?string $containerId = null,
        ?string $containerName = null,
    ): ServerCommandRun {
        $this->ensureCan($server, $actor);

        $run = ServerCommandRun::query()->create([
            'server_id' => $server->id,
            'server_recipe_id' => $recipe?->id,
            'source' => $source,
            'command' => $remoteCommand,
            'display_command' => $displayCommand,
            'container_scope_id' => $containerId !== '' ? $containerId : null,
            'container_scope_name' => $containerName !== '' ? $containerName : null,
            'status' => ServerCommandRun::STATUS_QUEUED,
            'queued_by_user_id' => $actor->id,
        ]);

        RunServerCommandJob::dispatch($run->id);

        return $run;
    }

    /**
     * Deployers may trigger deploys but not run arbitrary shell on the box.
     * Mirrors the inline check the WorkspaceRun component used to do.
     *
     * @throws ServerCommandNotPermittedException
     */
    protected function ensureCan(Server $server, User $actor): void
    {
        $organization = $actor->currentOrganization();

        if ($organization !== null && $organization->userIsDeployer($actor)) {
            throw new ServerCommandNotPermittedException(
                'Deployers cannot run server saved commands or arbitrary shell commands.'
            );
        }
    }
}
