<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Livewire\Servers\WorkspaceDaemons;
use App\Models\Server;

/**
 * View-model for the server Daemons workspace blade tree. Keeps catalog/setup
 * out of {@see resources/views/livewire/servers/workspace-daemons.blade.php}.
 */
final class DaemonWorkspaceViewData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Server $server, WorkspaceDaemons $component): array
    {
        $card = 'dply-card overflow-hidden';
        $opsReady = $server->isReady() && $server->ssh_private_key;

        $supervisorPresets = $component->supervisorPresetOptionsForForm();
        $supervisorFormSiteIsLaravel = $component->supervisorFormSiteIsLaravel();

        $programStatusBadgeClass = fn (string $state): string => match ($state) {
            'running' => 'bg-emerald-100 text-emerald-900 ring-emerald-200',
            'starting' => 'bg-amber-100 text-amber-900 ring-amber-200',
            'stopped' => 'bg-zinc-100 text-zinc-700 ring-zinc-200',
            'fatal', 'backoff', 'exited' => 'bg-red-100 text-red-800 ring-red-200',
            default => 'bg-brand-sand text-brand-moss ring-brand-ink/10',
        };

        $advancedFormOpen = trim($component->new_sv_env_lines) !== ''
            || trim($component->new_sv_stdout_logfile) !== ''
            || trim((string) ($component->new_sv_priority ?? '')) !== ''
            || trim((string) ($component->new_sv_startsecs ?? '')) !== ''
            || trim((string) ($component->new_sv_stopwaitsecs ?? '')) !== ''
            || trim($component->new_sv_autorestart) !== ''
            || ! $component->new_sv_redirect_stderr;

        return compact(
            'card',
            'opsReady',
            'supervisorPresets',
            'supervisorFormSiteIsLaravel',
            'programStatusBadgeClass',
            'advancedFormOpen',
        );
    }
}
