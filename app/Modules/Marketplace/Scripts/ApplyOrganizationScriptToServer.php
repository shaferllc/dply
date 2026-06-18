<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Scripts;

use App\Models\Organization;
use App\Models\Script;
use App\Models\Server;
use App\Models\ServerRecipe;
use App\Models\User;
use RuntimeException;

/**
 * Copy an organization script into a server-local saved command (Run workspace).
 */
final class ApplyOrganizationScriptToServer
{
    public function apply(Script $script, Server $server, User $actor, Organization $organization): ServerRecipe
    {
        if ((string) $script->organization_id !== (string) $organization->id) {
            throw new RuntimeException('Script does not belong to this organization.');
        }

        if ((string) $server->organization_id !== (string) $organization->id) {
            throw new RuntimeException('Server does not belong to this organization.');
        }

        if (! $server->isVmHost()) {
            throw new RuntimeException('Saved commands can only be applied to VM servers.');
        }

        $existing = ServerRecipe::query()
            ->where('server_id', $server->id)
            ->where('name', $script->name)
            ->first();

        if ($existing instanceof ServerRecipe) {
            $existing->update([
                'script' => $script->content,
                'user_id' => $actor->id,
            ]);

            return $existing->fresh();
        }

        return ServerRecipe::query()->create([
            'server_id' => $server->id,
            'user_id' => $actor->id,
            'name' => $script->name,
            'script' => $script->content,
        ]);
    }
}
