<?php

declare(strict_types=1);

namespace App\Services\Servers\Blueprint;

use App\Livewire\Forms\ServerCreateForm;
use App\Models\ServerBlueprint;
use App\Support\Servers\InstalledStack;

/**
 * Translate a stored blueprint snapshot into create-wizard form fields.
 */
final class ServerBlueprintApplier
{
    public function applyToForm(ServerCreateForm $form, ServerBlueprint $blueprint): void
    {
        $snapshot = $blueprint->snapshot;
        $stack = InstalledStack::fromArray(is_array($snapshot['stack'] ?? null) ? $snapshot['stack'] : []);

        $form->server_role = (string) ($snapshot['server_role'] ?? 'application');
        $form->webserver = $stack->webserver ?? 'none';
        $form->php_version = $stack->phpVersion ?? 'none';
        $form->database = $stack->database ?? 'none';
        $form->cache_service = $stack->cacheService ?? 'none';

        $runtimes = $snapshot['runtime_defaults'] ?? [];
        if (! is_array($runtimes)) {
            $runtimes = [];
        }

        $form->ruby_version = (string) ($runtimes['ruby'] ?? '');
        $form->node_version = (string) ($runtimes['node'] ?? '');
        $form->python_version = (string) ($runtimes['python'] ?? '');
        $form->go_version = (string) ($runtimes['go'] ?? '');

        $installProfile = (string) ($snapshot['install_profile'] ?? '');
        if ($installProfile !== '') {
            $form->install_profile = $installProfile;
        }

        $form->server_blueprint_id = $blueprint->id;
    }
}
