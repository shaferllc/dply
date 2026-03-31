<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Site;
use App\Models\SupervisorProgram;

/**
 * After a successful site deploy, optionally restart Supervisor programs tied to this site
 * (or server-wide programs with no site_id).
 */
class SupervisorDeployRestarter
{
    public function __construct(
        protected SupervisorProvisioner $provisioner
    ) {}

    public function restartAfterDeployIfEnabled(Site $site): string
    {
        if (! ($site->restart_supervisor_programs_after_deploy ?? false)) {
            return '';
        }

        $server = $site->server;
        if ($server === null || ! $server->isReady() || empty($server->ssh_private_key)) {
            return '';
        }

        $programs = SupervisorProgram::query()
            ->where('server_id', $server->id)
            ->where('is_active', true)
            ->where(function ($q) use ($site): void {
                $q->where('site_id', $site->id)->orWhereNull('site_id');
            })
            ->get();

        if ($programs->isEmpty()) {
            return "\n--- supervisor restart (skipped: no programs for this site) ---\n";
        }

        $log = "\n--- supervisor restart after deploy ---\n";
        foreach ($programs as $p) {
            try {
                $log .= $this->provisioner->restartProgramGroup($server, (string) $p->id)."\n";
            } catch (\Throwable $e) {
                $log .= 'restart '.$p->slug.': '.$e->getMessage()."\n";
            }
        }

        return $log;
    }
}
