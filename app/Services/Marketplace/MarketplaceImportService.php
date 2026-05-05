<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceItem;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerRecipe;
use App\Models\User;
use App\Models\WebserverTemplate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class MarketplaceImportService
{
    public function importWebserverTemplate(User $user, MarketplaceItem $item): WebserverTemplate
    {
        if ($item->recipe_type !== MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE) {
            throw new \InvalidArgumentException('Invalid recipe type.');
        }

        $organization = $user->currentOrganization();
        if (! $organization instanceof Organization) {
            throw new AuthorizationException(__('Select an organization first.'));
        }

        if (! $organization->hasAdminAccess($user)) {
            throw new AuthorizationException(__('Only organization admins can import webserver templates.'));
        }

        $payload = $item->payload;
        $label = (string) ($payload['label'] ?? $item->name);
        $content = (string) ($payload['content'] ?? '');

        return $organization->webserverTemplates()->create([
            'user_id' => $user->id,
            'label' => $label,
            'content' => $content,
        ]);
    }

    public function importDeployCommand(User $user, MarketplaceItem $item, Server $server): ServerRecipe
    {
        if ($item->recipe_type !== MarketplaceItem::RECIPE_DEPLOY_COMMAND) {
            throw new \InvalidArgumentException('Invalid recipe type.');
        }

        Gate::authorize('update', $server);

        $payload = $item->payload;
        $command = trim((string) ($payload['command'] ?? ''));

        if ($command === '') {
            throw new \InvalidArgumentException('Recipe has no command.');
        }

        // The legacy `deploy_command` column was dropped — marketplace
        // RECIPE_DEPLOY_COMMAND items now land as ServerRecipe rows so
        // the merged /run page can list and execute them like any other
        // saved command. The 'append' payload mode (used to concatenate
        // onto an existing single-field command) becomes meaningless
        // when each import is its own recipe row, so it's ignored.
        // Idempotency: if a recipe with the same name already exists
        // for this server, update its script instead of duplicating.
        $name = (string) ($payload['name'] ?? $item->name);

        $existing = ServerRecipe::query()
            ->where('server_id', $server->id)
            ->where('name', $name)
            ->first();

        if ($existing) {
            $existing->update(['script' => $command]);

            return $existing;
        }

        return ServerRecipe::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'name' => $name,
            'script' => $command,
        ]);
    }

    public function importServerRecipe(User $user, MarketplaceItem $item, Server $server): ServerRecipe
    {
        if ($item->recipe_type !== MarketplaceItem::RECIPE_SERVER_RECIPE) {
            throw new \InvalidArgumentException('Invalid recipe type.');
        }

        Gate::authorize('update', $server);

        $payload = $item->payload;
        $script = trim((string) ($payload['script'] ?? ''));

        if ($script === '') {
            throw new \InvalidArgumentException('Recipe has no script.');
        }

        return ServerRecipe::query()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'name' => (string) ($payload['name'] ?? $item->name),
            'script' => $script,
        ]);
    }
}
