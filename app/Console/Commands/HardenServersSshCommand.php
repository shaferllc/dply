<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Modules\Insights\Services\FixActions\HardenSshConfigFixAction;
use Illuminate\Console\Command;

/**
 * Apply key-only SSH hardening across the existing fleet in one shot.
 *
 *   dply:servers:harden-ssh                 # every ready server
 *   dply:servers:harden-ssh <server>        # one server (id, name, or IP)
 *   dply:servers:harden-ssh --dry-run       # list targets, change nothing
 *
 * Drops the same managed /etc/ssh/sshd_config.d/99-dply-hardening.conf snippet
 * (PasswordAuthentication no, PermitRootLogin prohibit-password, …) that
 * provisioning now writes on new boxes and the Insights ssh_security_posture
 * fix writes on demand — `sshd -t`-validated before reload. Idempotent: a server
 * already hardened is a no-op. Safe because dply connects as root via SSH key
 * and the deploy user is key + NOPASSWD-sudo, so password auth is unused.
 */
class HardenServersSshCommand extends Command
{
    protected $signature = 'dply:servers:harden-ssh
        {server? : Server ID, name, or IP (defaults to every ready server)}
        {--dry-run : List the servers that would be hardened, then exit}';

    protected $description = 'Lock SSH to key-only auth across existing servers (stops root-password brute force).';

    public function handle(): int
    {
        $needle = trim((string) ($this->argument('server') ?? ''));

        if ($needle !== '') {
            $server = Server::query()
                ->where('id', $needle)
                ->orWhere('name', $needle)
                ->orWhere('ip_address', $needle)
                ->first();
            if ($server === null) {
                $this->error("Server not found: {$needle}");

                return self::FAILURE;
            }
            $servers = collect([$server]);
        } else {
            $servers = Server::query()->orderBy('name')->get()
                ->filter(fn (Server $s): bool => $s->isReady() && filled($s->ssh_private_key))
                ->values();
        }

        if ($servers->isEmpty()) {
            $this->warn('No ready, SSH-reachable servers to harden.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Would harden SSH on:');
            foreach ($servers as $s) {
                $this->line(sprintf('  • %s (%s)', $s->name, $s->ip_address ?: $s->id));
            }

            return self::SUCCESS;
        }

        $action = app(HardenSshConfigFixAction::class);
        $failed = 0;

        foreach ($servers as $server) {
            if (! $server->isReady() || blank($server->ssh_private_key)) {
                $this->line(sprintf('<fg=gray>SKIP</> %s — not ready / no SSH key', $server->name));

                continue;
            }

            $this->output->write(sprintf('Hardening %s … ', $server->name));
            $result = $action->harden($server);

            if ($result->ok) {
                $this->line('<fg=green>OK</>');
            } else {
                $failed++;
                $this->line('<fg=red>FAILED</>');
                $this->line('  '.trim((string) ($result->errorMessage ?? $result->output)));
            }
        }

        $this->newLine();
        $this->info(sprintf('Done — %d hardened, %d failed.', $servers->count() - $failed, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
